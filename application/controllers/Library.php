<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Library Management Controller — Firestore-only.
 *
 * Sub-modules: Catalog, Categories, Issue/Return, Fines, Reports.
 *
 * Firestore collections (auto-scoped via Firestore_service::docId):
 *   libraryBooks/{schoolId}_{BK0001}            (apps read)
 *   libraryIssues/{schoolId}_{ISS0001}          (apps read)
 *   libraryFines/{schoolId}_{FN0001}            (apps read)
 *   bookCategories/{schoolId}_{CAT0001}
 *
 * Accounting integration: fine payment → Dr Cash 1010, Cr Late Fees 4060.
 */
class Library extends MY_Controller
{
    /** Roles that may manage library data */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian'];

    /** Roles that may view library data */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian', 'Academic Coordinator', 'Accountant', 'Class Teacher', 'Teacher'];

    const OPS_ADMIN_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];
    const LIB_MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian'];
    const LIB_VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian', 'Academic Coordinator', 'Accountant', 'Class Teacher', 'Teacher'];

    const COL_BOOKS      = 'libraryBooks';
    const COL_CATEGORIES = 'bookCategories';
    const COL_ISSUES     = 'libraryIssues';
    const COL_FINES      = 'libraryFines';

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );
        // $this->fs is the Firestore_service set by MY_Controller — reuse it.
    }

    // ── Role Guards ─────────────────────────────────────────────────────
    private function _require_manage()
    {
        if (!in_array($this->admin_role, self::LIB_MANAGE_ROLES, true))
            $this->json_error('Access denied.', 403);
    }
    private function _require_view()
    {
        if (!in_array($this->admin_role, self::LIB_VIEW_ROLES, true))
            $this->json_error('Access denied.', 403);
    }

    /** Firestore opsCounters key — operations_accounting::next_id normalises path → docId. */
    private function _counter_path(string $type): string
    {
        return "Schools/{$this->school_name}/Operations/Library/Counters/{$type}";
    }

    // ====================================================================
    //  PAGE LOAD
    // ====================================================================

    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'library_view');
        $tab = $this->uri->segment(2, 'catalog');
        $data = ['active_tab' => $tab];
        $this->load->view('include/header', $data);
        $this->load->view('library/index', $data);
        $this->load->view('include/footer');
    }

    // ====================================================================
    //  CATEGORIES
    // ====================================================================

    public function get_categories()
    {
        $this->_require_role(self::VIEW_ROLES, 'library_view');
        $this->_require_view();
        $rows = $this->firebase->firestoreQuery(self::COL_CATEGORIES,
            [['schoolId', '==', $this->school_name]], 'name', 'ASC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $c = is_array($doc) ? $doc : [];
            $id = (string) ($c['categoryId'] ?? $c['id'] ?? '');
            if ($id === '') continue;
            $c['id'] = $id;
            $list[] = $c;
        }
        $this->json_success(['categories' => $list]);
    }

    public function save_category()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_category');
        $this->_require_manage();
        $id   = trim($this->input->post('id') ?? '');
        $name = trim($this->input->post('name') ?? '');
        $desc = trim($this->input->post('description') ?? '');

        if ($name === '') $this->json_error('Category name is required.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counter_path('Category'), 'CAT');
        } else {
            $id = $this->safe_path_segment($id, 'category_id');
        }

        $existing = $this->fs->getEntity(self::COL_CATEGORIES, $id);

        $data = [
            'categoryId'  => $id,
            'name'        => $name,
            'description' => $desc,
            'status'      => 'Active',
            'updated_at'  => date('c'),
        ];
        if (!is_array($existing)) $data['created_at'] = date('c');

        $this->fs->setEntity(self::COL_CATEGORIES, $id, $data, /* merge */ true);
        $this->json_success(['id' => $id, 'message' => 'Category saved.']);
    }

    public function delete_category()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_category');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'category_id');

        // Block delete if books reference this category.
        $using = $this->firebase->firestoreQuery(self::COL_BOOKS, [
            ['schoolId', '==', $this->school_name],
            ['category', '==', $id],
        ]);
        if (!empty($using)) {
            $this->json_error('Cannot delete: books are assigned to this category.');
        }
        $this->fs->remove(self::COL_CATEGORIES, $this->fs->docId($id));
        $this->json_success(['message' => 'Category deleted.']);
    }

    // ====================================================================
    //  BOOKS (CATALOG)
    // ====================================================================

    /** GET — List books. Supports ?page=N&limit=N for pagination. */
    public function get_books()
    {
        $this->_require_role(self::VIEW_ROLES, 'library_view');
        $this->_require_view();
        $rows = $this->firebase->firestoreQuery(self::COL_BOOKS,
            [['schoolId', '==', $this->school_name]], 'title', 'ASC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $b = is_array($doc) ? $doc : [];
            $id = (string) ($b['bookId'] ?? $b['id'] ?? '');
            if ($id === '') continue;
            // Normalise for legacy admin JS (snake_case)
            $b['id']             = $id;
            $b['copies']         = (int) ($b['copies']    ?? $b['totalCopies']     ?? 0);
            $b['available']      = (int) ($b['available'] ?? $b['availableCopies'] ?? 0);
            $b['category_id']    = (string) ($b['category_id']    ?? $b['category'] ?? '');
            $b['shelf_location'] = (string) ($b['shelf_location'] ?? $b['location'] ?? '');
            if (!isset($b['author']) && isset($b['authors']) && is_array($b['authors'])) {
                $b['author'] = (string) ($b['authors'][0] ?? '');
            }
            $list[] = $b;
        }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'books', $this->input->get('page'), (int) ($this->input->get('limit') ?? 50)
        ));
    }

    public function save_book()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_book');
        $this->_require_manage();
        $id          = trim($this->input->post('id') ?? '');
        $title       = trim($this->input->post('title') ?? '');
        $author      = trim($this->input->post('author') ?? '');
        $isbn        = trim($this->input->post('isbn') ?? '');
        $categoryId  = trim($this->input->post('category_id') ?? '');
        $publisher   = trim($this->input->post('publisher') ?? '');
        $edition     = trim($this->input->post('edition') ?? '');
        $copies      = max(0, (int) ($this->input->post('copies') ?? 1));
        $shelf       = trim($this->input->post('shelf_location') ?? '');
        $description = trim($this->input->post('description') ?? '');

        if ($title === '') $this->json_error('Book title is required.');
        if ($copies < 1)  $this->json_error('At least 1 copy is required.');

        $isNew = ($id === '');
        if ($isNew) {
            $id = $this->operations_accounting->next_id($this->_counter_path('Book'), 'BK');
            $available = $copies;
        } else {
            $id = $this->safe_path_segment($id, 'book_id');
            $existing = $this->fs->getEntity(self::COL_BOOKS, $id);
            if (!is_array($existing)) $this->json_error('Book not found.');
            $oldCopies = (int) ($existing['copies']    ?? $existing['totalCopies']     ?? 0);
            $oldAvail  = (int) ($existing['available'] ?? $existing['availableCopies'] ?? 0);
            $available = max(0, $oldAvail + ($copies - $oldCopies));
        }

        // Dual-emit snake_case (admin JS) + camelCase (apps).
        $data = [
            'bookId'          => $id,
            'title'           => $title,
            'searchTitle'     => strtolower($title),
            'author'          => $author,
            'authors'         => [$author],
            'isbn'            => $isbn,
            'category_id'     => $categoryId,
            'category'        => $categoryId,
            'publisher'       => $publisher,
            'edition'         => $edition,
            'copies'          => $copies,
            'available'       => $available,
            'totalCopies'     => $copies,
            'availableCopies' => $available,
            'shelf_location'  => $shelf,
            'location'        => $shelf,
            'description'     => $description,
            'status'          => 'Active',
            'updated_at'      => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->fs->setEntity(self::COL_BOOKS, $id, $data, /* merge */ !$isNew);
        $this->json_success(['id' => $id, 'message' => 'Book saved.']);
    }

    public function delete_book()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_book');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'book_id');

        // Block delete while active issues reference this book.
        $activeIssues = $this->firebase->firestoreQuery(self::COL_ISSUES, [
            ['schoolId', '==', $this->school_name],
            ['bookId',   '==', $id],
            ['status',   '==', 'issued'],
        ]);
        if (!empty($activeIssues)) {
            $this->json_error('Cannot delete: book has active issues.');
        }
        $this->fs->remove(self::COL_BOOKS, $this->fs->docId($id));
        $this->json_success(['message' => 'Book deleted.']);
    }

    // ====================================================================
    //  ISSUE / RETURN
    // ====================================================================

    /** POST — Issue a book to a student. */
    public function issue_book()
    {
        $this->_require_role(self::MANAGE_ROLES, 'issue_book');
        $this->_require_manage();
        $bookId    = $this->safe_path_segment(trim($this->input->post('book_id') ?? ''), 'book_id');
        $studentId = $this->safe_path_segment(trim($this->input->post('student_id') ?? ''), 'student_id');
        $dueDate   = trim($this->input->post('due_date') ?? '');

        if ($bookId === '' || $studentId === '') $this->json_error('Book ID and Student ID are required.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) $this->json_error('Valid due date (YYYY-MM-DD) is required.');

        $book = $this->fs->getEntity(self::COL_BOOKS, $bookId);
        if (!is_array($book)) $this->json_error('Book not found.');
        $available = (int) ($book['available'] ?? $book['availableCopies'] ?? 0);
        if ($available < 1) $this->json_error('No copies available for issue.');

        // Student lookup via Firestore students collection.
        $student = $this->fs->getEntity('students', $studentId);
        if (!is_array($student)) $this->json_error('Student not found.');
        $studentName = (string) ($student['name'] ?? $student['Name'] ?? $studentId);

        // Reject if student already holds this book.
        $dupe = $this->firebase->firestoreQuery(self::COL_ISSUES, [
            ['schoolId',   '==', $this->school_name],
            ['bookId',     '==', $bookId],
            ['borrowerId', '==', $studentId],
            ['status',     '==', 'issued'],
        ]);
        if (!empty($dupe)) {
            $this->json_error('Student already has this book issued.');
        }

        $issueId = $this->operations_accounting->next_id($this->_counter_path('Issue'), 'ISS');

        // Dual-emit snake_case + camelCase. `status=issued` (camelCase apps) — the
        // admin report (get_reports) still checks `status === 'Issued'` via
        // case-insensitive path; issues collection standardises on lowercase.
        $issueData = [
            'issueId'      => $issueId,
            'book_id'      => $bookId,
            'bookId'       => $bookId,
            'book_title'   => $book['title'] ?? '',
            'bookTitle'    => $book['title'] ?? '',
            'student_id'   => $studentId,
            'borrowerId'   => $studentId,
            'student_name' => $studentName,
            'borrowerName' => $studentName,
            'borrowerType' => 'student',
            'issue_date'   => date('Y-m-d'),
            'issueDate'    => date('Y-m-d'),
            'due_date'     => $dueDate,
            'dueDate'      => $dueDate,
            'return_date'  => '',
            'returnDate'   => '',
            'renewals'     => 0,
            'maxRenewals'  => 2,
            'fine_amount'  => 0,
            'fine'         => 0.0,
            'status'       => 'issued',
            'issued_by'    => $this->admin_name,
            'issuedBy'     => $this->admin_name,
            'created_at'   => date('c'),
            'createdAt'    => date('c'),
        ];

        $this->fs->setEntity(self::COL_ISSUES, $issueId, $issueData, /* merge */ false);

        // Decrement book's available count.
        $newAvailable = $available - 1;
        $this->fs->updateEntity(self::COL_BOOKS, $bookId, [
            'available'       => $newAvailable,
            'availableCopies' => $newAvailable,
            'updated_at'      => date('c'),
        ]);

        $this->json_success(['id' => $issueId, 'message' => "Book issued to {$studentName}."]);
    }

    /** POST — Return a book. Calculates late fine if applicable. */
    public function return_book()
    {
        $this->_require_role(self::MANAGE_ROLES, 'return_book');
        $this->_require_manage();
        $issueId    = $this->safe_path_segment(trim($this->input->post('issue_id') ?? ''), 'issue_id');
        $finePerDay = max(0, (float) ($this->input->post('fine_per_day') ?? 2));

        $issue = $this->fs->getEntity(self::COL_ISSUES, $issueId);
        if (!is_array($issue)) $this->json_error('Issue record not found.');
        $issueStatus = strtolower((string) ($issue['status'] ?? ''));
        if ($issueStatus !== 'issued') $this->json_error('Book is not currently issued.');

        $today   = date('Y-m-d');
        $dueDate = $issue['due_date'] ?? $issue['dueDate'] ?? $today;

        $fineAmount = 0;
        $lateDays   = 0;
        if ($today > $dueDate) {
            $lateDays   = (int) (new DateTime($dueDate))->diff(new DateTime($today))->days;
            $fineAmount = round($lateDays * $finePerDay, 2);
        }

        $this->fs->updateEntity(self::COL_ISSUES, $issueId, [
            'return_date' => $today,
            'returnDate'  => $today,
            'fine_amount' => $fineAmount,
            'fine'        => $fineAmount,
            'late_days'   => $lateDays,
            'status'      => 'returned',
            'returned_by' => $this->admin_name,
            'returnedTo'  => $this->admin_name,
            'returned_at' => date('c'),
        ]);

        // Increment available count on book.
        $bookId = (string) ($issue['book_id'] ?? $issue['bookId'] ?? '');
        $book   = null;
        if ($bookId !== '') {
            $book = $this->fs->getEntity(self::COL_BOOKS, $bookId);
            if (is_array($book)) {
                $newAvail = (int) ($book['available'] ?? $book['availableCopies'] ?? 0) + 1;
                $this->fs->updateEntity(self::COL_BOOKS, $bookId, [
                    'available'       => $newAvail,
                    'availableCopies' => $newAvail,
                    'updated_at'      => date('c'),
                ]);
            }
        }

        // Create fine record if late.
        $fineId = '';
        if ($fineAmount > 0) {
            $fineId = $this->operations_accounting->next_id($this->_counter_path('Fine'), 'FN');
            $this->fs->setEntity(self::COL_FINES, $fineId, [
                'fineId'       => $fineId,
                'issue_id'     => $issueId,
                'issueId'      => $issueId,
                'bookId'       => $bookId,
                'book_title'   => $issue['book_title'] ?? $issue['bookTitle'] ?? '',
                'bookTitle'    => $issue['book_title'] ?? $issue['bookTitle'] ?? '',
                'student_id'   => $issue['student_id'] ?? $issue['borrowerId'] ?? '',
                'borrowerId'   => $issue['student_id'] ?? $issue['borrowerId'] ?? '',
                'student_name' => $issue['student_name'] ?? $issue['borrowerName'] ?? '',
                'borrowerName' => $issue['student_name'] ?? $issue['borrowerName'] ?? '',
                'late_days'    => $lateDays,
                'amount'       => $fineAmount,
                'fineAmount'   => $fineAmount,
                'reason'       => 'overdue',
                'paid'         => false,
                'journal_id'   => '',
                'status'       => 'pending',
                'created_at'   => date('c'),
                'createdAt'    => date('c'),
            ], /* merge */ false);
        }

        $msg = 'Book returned successfully.';
        if ($fineAmount > 0) {
            $msg .= " Late by {$lateDays} day(s). Fine: Rs {$fineAmount}.";
        }

        $this->json_success([
            'message'     => $msg,
            'fine_amount' => $fineAmount,
            'fine_id'     => $fineId,
            'late_days'   => $lateDays,
        ]);
    }

    /** GET — List issue records. ?status=Issued|Returned&page=N&limit=N */
    public function get_issues()
    {
        $this->_require_role(self::VIEW_ROLES, 'library_view');
        $this->_require_view();
        $filterStatus = strtolower(trim($this->input->get('status') ?? ''));

        $where = [['schoolId', '==', $this->school_name]];
        if ($filterStatus !== '') $where[] = ['status', '==', $filterStatus];

        $rows = $this->firebase->firestoreQuery(self::COL_ISSUES, $where, 'createdAt', 'DESC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $iss = is_array($doc) ? $doc : [];
            $id = (string) ($iss['issueId'] ?? $iss['id'] ?? '');
            if ($id === '') continue;
            $iss['id'] = $id;
            // Normalise for legacy admin JS (snake_case).
            $iss['book_id']      = (string) ($iss['book_id']      ?? $iss['bookId']       ?? '');
            $iss['book_title']   = (string) ($iss['book_title']   ?? $iss['bookTitle']    ?? '');
            $iss['student_id']   = (string) ($iss['student_id']   ?? $iss['borrowerId']   ?? '');
            $iss['student_name'] = (string) ($iss['student_name'] ?? $iss['borrowerName'] ?? '');
            $iss['issue_date']   = (string) ($iss['issue_date']   ?? $iss['issueDate']    ?? '');
            $iss['due_date']     = (string) ($iss['due_date']     ?? $iss['dueDate']      ?? '');
            $iss['return_date']  = (string) ($iss['return_date']  ?? $iss['returnDate']   ?? '');
            $list[] = $iss;
        }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'issues', $this->input->get('page'), (int) ($this->input->get('limit') ?? 50)
        ));
    }

    // ====================================================================
    //  FINES
    // ====================================================================

    /** GET — List fines. ?status=Pending|Paid&page=N&limit=N */
    public function get_fines()
    {
        $this->_require_role(self::VIEW_ROLES, 'library_view');
        $this->_require_view();
        $filterStatus = strtolower(trim($this->input->get('status') ?? ''));

        $where = [['schoolId', '==', $this->school_name]];
        if ($filterStatus !== '') $where[] = ['status', '==', $filterStatus];

        $rows = $this->firebase->firestoreQuery(self::COL_FINES, $where, 'createdAt', 'DESC');
        $list = [];
        foreach ((array) $rows as $doc) {
            $f = is_array($doc) ? $doc : [];
            $id = (string) ($f['fineId'] ?? $f['id'] ?? '');
            if ($id === '') continue;
            $f['id']           = $id;
            $f['amount']       = (float) ($f['amount']       ?? $f['fineAmount']   ?? 0);
            $f['student_id']   = (string) ($f['student_id']   ?? $f['borrowerId']   ?? '');
            $f['student_name'] = (string) ($f['student_name'] ?? $f['borrowerName'] ?? '');
            $f['book_title']   = (string) ($f['book_title']   ?? $f['bookTitle']    ?? '');
            $list[] = $f;
        }
        $this->json_success($this->operations_accounting->paginate(
            $list, 'fines', $this->input->get('page'), (int) ($this->input->get('limit') ?? 50)
        ));
    }

    /** POST — Pay a fine. Creates accounting journal entry. */
    public function pay_fine()
    {
        $this->_require_role(self::MANAGE_ROLES, 'pay_fine');
        $this->_require_manage();
        $fineId      = $this->safe_path_segment(trim($this->input->post('fine_id') ?? ''), 'fine_id');
        $paymentMode = trim($this->input->post('payment_mode') ?? 'Cash');

        $fine = $this->fs->getEntity(self::COL_FINES, $fineId);
        if (!is_array($fine)) $this->json_error('Fine not found.');
        $status = strtolower((string) ($fine['status'] ?? ''));
        if ($status === 'paid') $this->json_error('Fine already paid.');

        $amount    = (float) ($fine['amount'] ?? $fine['fineAmount'] ?? 0);
        $cashAcct  = ($paymentMode === 'Bank') ? '1020' : '1010';
        $studentNm = (string) ($fine['student_name'] ?? $fine['borrowerName'] ?? '');
        $bookTitle = (string) ($fine['book_title']   ?? $fine['bookTitle']    ?? '');

        $this->operations_accounting->validate_accounts([$cashAcct, '4060']);

        $narration = "Library fine payment - {$studentNm} - {$bookTitle}";
        $journalId = $this->operations_accounting->create_journal($narration, [
            ['account_code' => $cashAcct, 'dr' => $amount, 'cr' => 0],
            ['account_code' => '4060',    'dr' => 0,       'cr' => $amount],
        ], 'Library', $fineId);

        $this->fs->updateEntity(self::COL_FINES, $fineId, [
            'paid'         => true,
            'journal_id'   => $journalId,
            'payment_mode' => $paymentMode,
            'status'       => 'paid',
            'paid_at'      => date('c'),
            'paidAt'       => date('c'),
            'paid_by'      => $this->admin_name,
        ]);

        $this->json_success([
            'message'    => "Fine of Rs {$amount} paid. Journal: {$journalId}.",
            'journal_id' => $journalId,
        ]);
    }

    // ====================================================================
    //  REPORTS
    // ====================================================================

    /** GET — Library report data. */
    public function get_reports()
    {
        $this->_require_role(self::VIEW_ROLES, 'library_view');
        $this->_require_view();

        $bookRows  = $this->firebase->firestoreQuery(self::COL_BOOKS,  [['schoolId', '==', $this->school_name]]);
        $issueRows = $this->firebase->firestoreQuery(self::COL_ISSUES, [['schoolId', '==', $this->school_name]]);
        $fineRows  = $this->firebase->firestoreQuery(self::COL_FINES,  [['schoolId', '==', $this->school_name]]);

        $today       = date('Y-m-d');
        $totalBooks  = 0;
        $totalCopies = 0;
        $available   = 0;
        foreach ((array) $bookRows as $b) {
            if (!is_array($b)) continue;
            $totalBooks++;
            $totalCopies += (int) ($b['copies']    ?? $b['totalCopies']     ?? 0);
            $available   += (int) ($b['available'] ?? $b['availableCopies'] ?? 0);
        }

        $currentlyIssued = 0;
        $overdue         = 0;
        $totalReturned   = 0;
        foreach ((array) $issueRows as $iss) {
            if (!is_array($iss)) continue;
            $s = strtolower((string) ($iss['status'] ?? ''));
            if ($s === 'issued') {
                $currentlyIssued++;
                $due = (string) ($iss['due_date'] ?? $iss['dueDate'] ?? '');
                if ($due !== '' && $due < $today) $overdue++;
            } else {
                $totalReturned++;
            }
        }

        $totalFines   = 0;
        $pendingFines = 0;
        $paidFines    = 0;
        foreach ((array) $fineRows as $f) {
            if (!is_array($f)) continue;
            $amt = (float) ($f['amount'] ?? $f['fineAmount'] ?? 0);
            $totalFines += $amt;
            $s = strtolower((string) ($f['status'] ?? ''));
            if ($s === 'paid') {
                $paidFines += $amt;
            } else {
                $pendingFines += $amt;
            }
        }

        $this->json_success([
            'report' => [
                'total_titles'     => $totalBooks,
                'total_copies'     => $totalCopies,
                'available_copies' => $available,
                'currently_issued' => $currentlyIssued,
                'overdue'          => $overdue,
                'total_returned'   => $totalReturned,
                'total_fines'      => round($totalFines, 2),
                'pending_fines'    => round($pendingFines, 2),
                'paid_fines'       => round($paidFines, 2),
            ],
        ]);
    }

    /** GET — Search students for issue form. ?q=name_fragment */
    public function search_students()
    {
        $this->_require_role(self::VIEW_ROLES, 'library_view');
        $this->_require_view();
        $results = $this->operations_accounting->search_students(
            $this->input->get('q') ?? ''
        );
        $this->json_success(['students' => $results]);
    }
}
