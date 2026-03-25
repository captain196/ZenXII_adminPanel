<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Library Management Controller
 *
 * Sub-modules: Catalog, Categories, Issue/Return, Fines, Reports
 *
 * Firebase paths:
 *   Schools/{school}/Operations/Library/Books/{BK0001}
 *   Schools/{school}/Operations/Library/Categories/{CAT0001}
 *   Schools/{school}/Operations/Library/Issues/{ISS0001}
 *   Schools/{school}/Operations/Library/Fines/{FN0001}
 *   Schools/{school}/Operations/Library/Counters/{type}
 *
 * Accounting integration:
 *   Fine payment → journal (Dr Cash 1010, Cr Late Fees 4060)
 *
 * Extends MY_Controller: $this->school_name, $this->firebase,
 *   safe_path_segment(), json_success(), json_error()
 */
class Library extends MY_Controller
{
    /** Roles that may manage library data (add/edit/delete books, issues, fines) */
    private const MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian'];

    /** Roles that may view library data (dashboard, catalog, reports) */
    private const VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian', 'Academic Coordinator', 'Accountant', 'Class Teacher', 'Teacher'];

    const OPS_ADMIN_ROLES  = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal'];
    const LIB_MANAGE_ROLES = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian'];
    const LIB_VIEW_ROLES   = ['Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal', 'Operations Manager', 'Librarian', 'Academic Coordinator', 'Accountant', 'Class Teacher', 'Teacher'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('operations_accounting');
        $this->operations_accounting->init(
            $this->firebase, $this->school_name, $this->session_year, $this->admin_id, $this, $this->parent_db_key
        );

        // Firestore dual-write helper (named database "schoolsync")
        $this->load->library('Firestore_helper', null, 'fs');
        $this->fs->init($this->firebase, $this->school_name, $this->session_year);
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

    // ── Path Helpers ────────────────────────────────────────────────────
    private function _lib(string $sub = ''): string
    {
        $b = "Schools/{$this->school_name}/Operations/Library";
        return $sub !== '' ? "{$b}/{$sub}" : $b;
    }
    private function _books(string $id = ''): string
    {
        return $id !== '' ? $this->_lib("Books/{$id}") : $this->_lib('Books');
    }
    private function _cats(string $id = ''): string
    {
        return $id !== '' ? $this->_lib("Categories/{$id}") : $this->_lib('Categories');
    }
    private function _issues(string $id = ''): string
    {
        return $id !== '' ? $this->_lib("Issues/{$id}") : $this->_lib('Issues');
    }
    private function _fines(string $id = ''): string
    {
        return $id !== '' ? $this->_lib("Fines/{$id}") : $this->_lib('Fines');
    }
    private function _counters(string $type = ''): string
    {
        return $type !== '' ? $this->_lib("Counters/{$type}") : $this->_lib('Counters');
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
        $cats = $this->firebase->get($this->_cats());
        $list = [];
        if (is_array($cats)) {
            foreach ($cats as $id => $c) { $c['id'] = $id; $list[] = $c; }
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

        if ($id === '') {
            $id = $this->operations_accounting->next_id($this->_counters('Category'), 'CAT');
        } else {
            $id = $this->safe_path_segment($id, 'category_id');
        }

        $data = [
            'name'        => $name,
            'description' => $desc,
            'status'      => 'Active',
            'updated_at'  => date('c'),
        ];
        if (!$this->firebase->get($this->_cats($id))) {
            $data['created_at'] = date('c');
        }
        $this->firebase->set($this->_cats($id), $data);
        $this->json_success(['id' => $id, 'message' => 'Category saved.']);
    }

    public function delete_category()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_category');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'category_id');

        // Check if books use this category
        $books = $this->firebase->get($this->_books());
        if (is_array($books)) {
            foreach ($books as $bk) {
                if (($bk['category_id'] ?? '') === $id) {
                    $this->json_error('Cannot delete: books are assigned to this category.');
                }
            }
        }
        $this->firebase->delete($this->_cats(), $id);
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
        $books = $this->firebase->get($this->_books());
        $list = [];
        if (is_array($books)) {
            foreach ($books as $id => $b) { $b['id'] = $id; $list[] = $b; }
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
            $id = $this->operations_accounting->next_id($this->_counters('Book'), 'BK');
            $available = $copies;
        } else {
            $id = $this->safe_path_segment($id, 'book_id');
            $existing = $this->firebase->get($this->_books($id));
            if (!is_array($existing)) $this->json_error('Book not found.');
            // Adjust available count based on copies change
            $oldCopies = (int) ($existing['copies'] ?? 0);
            $oldAvail  = (int) ($existing['available'] ?? 0);
            $available = max(0, $oldAvail + ($copies - $oldCopies));
        }

        $data = [
            'title'          => $title,
            'author'         => $author,
            'isbn'           => $isbn,
            'category_id'    => $categoryId,
            'publisher'      => $publisher,
            'edition'        => $edition,
            'copies'         => $copies,
            'available'      => $available,
            'shelf_location' => $shelf,
            'description'    => $description,
            'status'         => 'Active',
            'updated_at'     => date('c'),
        ];
        if ($isNew) $data['created_at'] = date('c');

        $this->firebase->set($this->_books($id), $data);

        // ── Dual-write to Firestore for mobile apps ──
        try {
            $fsDocId = "{$this->school_name}_{$id}";
            $fsData  = [
                'title'          => $title,
                'authors'        => [$author],
                'isbn'           => $isbn,
                'category'       => $categoryId,
                'publisher'      => $publisher,
                'edition'        => $edition,
                'totalCopies'    => $copies,
                'availableCopies'=> $available,
                'location'       => $shelf,
                'schoolId'       => $this->school_name,
                'searchTitle'    => strtolower($title),
                'status'         => 'available',
                'updatedAt'      => date('c'),
            ];
            if ($description !== '') {
                $fsData['description'] = $description;
            }
            if ($isNew) {
                $fsData['createdAt'] = date('c');
            }
            $this->fs->set(Firestore_helper::LIBRARY_BOOKS, $fsDocId, $fsData, !$isNew);
        } catch (\Exception $e) {
            log_message('error', "save_book: Firestore sync failed [{$id}]: " . $e->getMessage());
        }

        $this->json_success(['id' => $id, 'message' => 'Book saved.']);
    }

    public function delete_book()
    {
        $this->_require_role(self::MANAGE_ROLES, 'delete_book');
        $this->_require_manage();
        $id = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'book_id');

        // Check active issues
        $issues = $this->firebase->get($this->_issues());
        if (is_array($issues)) {
            foreach ($issues as $iss) {
                if (($iss['book_id'] ?? '') === $id && ($iss['status'] ?? '') === 'Issued') {
                    $this->json_error('Cannot delete: book has active issues.');
                }
            }
        }
        $this->firebase->delete($this->_books(), $id);

        // ── Dual-delete from Firestore ──
        try {
            $fsDocId = "{$this->school_name}_{$id}";
            $this->fs->delete(Firestore_helper::LIBRARY_BOOKS, $fsDocId);
        } catch (\Exception $e) {
            log_message('error', "delete_book: Firestore sync failed [{$id}]: " . $e->getMessage());
        }

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

        // Verify book exists and is available
        $book = $this->firebase->get($this->_books($bookId));
        if (!is_array($book)) $this->json_error('Book not found.');
        if ((int) ($book['available'] ?? 0) < 1) $this->json_error('No copies available for issue.');

        // Verify student (use parent_db_key — legacy schools key by school_code, not school_id)
        $student = $this->firebase->get("Users/Parents/{$this->parent_db_key}/{$studentId}");
        if (!is_array($student)) $this->json_error('Student not found.');

        // Check if student already has this book
        $issues = $this->firebase->get($this->_issues());
        if (is_array($issues)) {
            foreach ($issues as $iss) {
                if (($iss['book_id'] ?? '') === $bookId &&
                    ($iss['student_id'] ?? '') === $studentId &&
                    ($iss['status'] ?? '') === 'Issued') {
                    $this->json_error('Student already has this book issued.');
                }
            }
        }

        $issueId = $this->operations_accounting->next_id($this->_counters('Issue'), 'ISS');

        $issueData = [
            'book_id'      => $bookId,
            'book_title'   => $book['title'] ?? '',
            'student_id'   => $studentId,
            'student_name' => $student['Name'] ?? $studentId,
            'issue_date'   => date('Y-m-d'),
            'due_date'     => $dueDate,
            'return_date'  => '',
            'fine_amount'  => 0,
            'status'       => 'Issued',
            'issued_by'    => $this->admin_name,
            'created_at'   => date('c'),
        ];

        $this->firebase->set($this->_issues($issueId), $issueData);

        // Decrement available count
        $newAvailable = (int) $book['available'] - 1;
        $this->firebase->set($this->_books($bookId) . '/available', $newAvailable);

        // ── Dual-write to Firestore for mobile apps ──
        try {
            // Write issue document
            $fsIssueDocId = "{$this->school_name}_{$issueId}";
            $this->fs->set(Firestore_helper::LIBRARY_ISSUES, $fsIssueDocId, [
                'bookId'       => $bookId,
                'bookTitle'    => $issueData['book_title'],
                'borrowerId'   => $studentId,
                'borrowerName' => $issueData['student_name'],
                'borrowerType' => 'student',
                'issueDate'    => $issueData['issue_date'],
                'dueDate'      => $dueDate,
                'returnDate'   => '',
                'renewals'     => 0,
                'maxRenewals'  => 2,
                'fine'         => 0.0,
                'status'       => 'issued',
                'issuedBy'     => $issueData['issued_by'],
                'schoolId'     => $this->school_name,
                'createdAt'    => date('c'),
            ]);

            // Update book's availableCopies in Firestore
            $fsBookDocId = "{$this->school_name}_{$bookId}";
            $this->fs->update(Firestore_helper::LIBRARY_BOOKS, $fsBookDocId, [
                'availableCopies' => $newAvailable,
                'updatedAt'       => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "issue_book: Firestore sync failed [{$issueId}]: " . $e->getMessage());
        }

        $this->json_success(['id' => $issueId, 'message' => "Book issued to {$issueData['student_name']}."]);
    }

    /** POST — Return a book. Calculates late fine if applicable. */
    public function return_book()
    {
        $this->_require_role(self::MANAGE_ROLES, 'return_book');
        $this->_require_manage();
        $issueId    = $this->safe_path_segment(trim($this->input->post('issue_id') ?? ''), 'issue_id');
        $finePerDay = max(0, (float) ($this->input->post('fine_per_day') ?? 2));

        $issue = $this->firebase->get($this->_issues($issueId));
        if (!is_array($issue)) $this->json_error('Issue record not found.');
        if (($issue['status'] ?? '') !== 'Issued') $this->json_error('Book is not currently issued.');

        $today   = date('Y-m-d');
        $dueDate = $issue['due_date'] ?? $today;

        // Calculate fine for late return
        $fineAmount = 0;
        $lateDays   = 0;
        if ($today > $dueDate) {
            $lateDays   = (int) (new DateTime($dueDate))->diff(new DateTime($today))->days;
            $fineAmount = round($lateDays * $finePerDay, 2);
        }

        // Update issue record
        $this->firebase->update($this->_issues($issueId), [
            'return_date' => $today,
            'fine_amount' => $fineAmount,
            'late_days'   => $lateDays,
            'status'      => 'Returned',
            'returned_by' => $this->admin_name,
            'returned_at' => date('c'),
        ]);

        // Increment available count
        $bookId = $issue['book_id'] ?? '';
        if ($bookId !== '') {
            $book = $this->firebase->get($this->_books($bookId));
            if (is_array($book)) {
                $this->firebase->set($this->_books($bookId) . '/available', (int) ($book['available'] ?? 0) + 1);
            }
        }

        // Create fine record if late
        $fineId = '';
        if ($fineAmount > 0) {
            $fineId = $this->operations_accounting->next_id($this->_counters('Fine'), 'FN');
            $this->firebase->set($this->_fines($fineId), [
                'issue_id'     => $issueId,
                'student_id'   => $issue['student_id'] ?? '',
                'student_name' => $issue['student_name'] ?? '',
                'book_title'   => $issue['book_title'] ?? '',
                'late_days'    => $lateDays,
                'amount'       => $fineAmount,
                'paid'         => false,
                'journal_id'   => '',
                'status'       => 'Pending',
                'created_at'   => date('c'),
            ]);
        }

        // ── Dual-write to Firestore for mobile apps ──
        try {
            // Update issue document
            $fsIssueDocId = "{$this->school_name}_{$issueId}";
            $this->fs->update(Firestore_helper::LIBRARY_ISSUES, $fsIssueDocId, [
                'status'     => 'returned',
                'returnDate' => $today,
                'returnedTo' => $this->admin_name,
                'fine'       => $fineAmount,
                'updatedAt'  => date('c'),
            ]);

            // Update book's availableCopies in Firestore
            if ($bookId !== '' && is_array($book ?? null)) {
                $fsBookDocId = "{$this->school_name}_{$bookId}";
                $this->fs->update(Firestore_helper::LIBRARY_BOOKS, $fsBookDocId, [
                    'availableCopies' => (int) ($book['available'] ?? 0) + 1,
                    'updatedAt'       => date('c'),
                ]);
            }

            // Create fine document in Firestore if applicable
            if ($fineAmount > 0 && $fineId !== '') {
                $fsFineDocId = "{$this->school_name}_{$fineId}";
                $this->fs->set(Firestore_helper::LIBRARY_FINES, $fsFineDocId, [
                    'issueId'      => $issueId,
                    'bookId'       => $bookId,
                    'bookTitle'    => $issue['book_title'] ?? '',
                    'borrowerId'   => $issue['student_id'] ?? '',
                    'borrowerName' => $issue['student_name'] ?? '',
                    'fineAmount'   => $fineAmount,
                    'reason'       => 'overdue',
                    'status'       => 'pending',
                    'schoolId'     => $this->school_name,
                    'createdAt'    => date('c'),
                ]);
            }
        } catch (\Exception $e) {
            log_message('error', "return_book: Firestore sync failed [{$issueId}]: " . $e->getMessage());
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
        $filterStatus = trim($this->input->get('status') ?? '');

        $issues = $this->firebase->get($this->_issues());
        $list = [];
        if (is_array($issues)) {
            foreach ($issues as $id => $iss) {
                if ($filterStatus !== '' && ($iss['status'] ?? '') !== $filterStatus) continue;
                $iss['id'] = $id;
                $list[] = $iss;
            }
        }
        usort($list, function ($a, $b) {
            return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
        });
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
        $filterStatus = trim($this->input->get('status') ?? '');

        $fines = $this->firebase->get($this->_fines());
        $list = [];
        if (is_array($fines)) {
            foreach ($fines as $id => $f) {
                if ($filterStatus !== '' && ($f['status'] ?? '') !== $filterStatus) continue;
                $f['id'] = $id;
                $list[] = $f;
            }
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

        $fine = $this->firebase->get($this->_fines($fineId));
        if (!is_array($fine)) $this->json_error('Fine not found.');
        if (($fine['status'] ?? '') === 'Paid') $this->json_error('Fine already paid.');

        $amount   = (float) ($fine['amount'] ?? 0);
        $cashAcct = ($paymentMode === 'Bank') ? '1020' : '1010';

        // Validate accounts before journal
        $this->operations_accounting->validate_accounts([$cashAcct, '4060']);

        // Create journal: Dr Cash/Bank, Cr Late Fees
        $narration = "Library fine payment - {$fine['student_name']} - {$fine['book_title']}";
        $journalId = $this->operations_accounting->create_journal($narration, [
            ['account_code' => $cashAcct, 'dr' => $amount, 'cr' => 0],
            ['account_code' => '4060',    'dr' => 0,       'cr' => $amount],
        ], 'Library', $fineId);

        // Update fine record
        $this->firebase->update($this->_fines($fineId), [
            'paid'         => true,
            'journal_id'   => $journalId,
            'payment_mode' => $paymentMode,
            'status'       => 'Paid',
            'paid_at'      => date('c'),
            'paid_by'      => $this->admin_name,
        ]);

        // ── Dual-write to Firestore for mobile apps ──
        try {
            $fsFineDocId = "{$this->school_name}_{$fineId}";
            $this->fs->update(Firestore_helper::LIBRARY_FINES, $fsFineDocId, [
                'status'    => 'paid',
                'paidAt'    => date('c'),
                'updatedAt' => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "pay_fine: Firestore sync failed [{$fineId}]: " . $e->getMessage());
        }

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

        $books  = $this->firebase->get($this->_books())  ?? [];
        $issues = $this->firebase->get($this->_issues())  ?? [];
        $fines  = $this->firebase->get($this->_fines())   ?? [];
        if (!is_array($books))  $books  = [];
        if (!is_array($issues)) $issues = [];
        if (!is_array($fines))  $fines  = [];

        $today       = date('Y-m-d');
        $totalBooks  = 0;
        $totalCopies = 0;
        $available   = 0;
        foreach ($books as $b) {
            $totalBooks++;
            $totalCopies += (int) ($b['copies'] ?? 0);
            $available   += (int) ($b['available'] ?? 0);
        }

        $currentlyIssued = 0;
        $overdue         = 0;
        $totalReturned   = 0;
        foreach ($issues as $iss) {
            if (($iss['status'] ?? '') === 'Issued') {
                $currentlyIssued++;
                if (($iss['due_date'] ?? '') < $today) $overdue++;
            } else {
                $totalReturned++;
            }
        }

        $totalFines   = 0;
        $pendingFines = 0;
        $paidFines    = 0;
        foreach ($fines as $f) {
            $amt = (float) ($f['amount'] ?? 0);
            $totalFines += $amt;
            if (($f['status'] ?? '') === 'Paid') {
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
