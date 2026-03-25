<div class="content-wrapper">
    <section class="content section-students-page">

        <!-- ── Gold Gradient Header ── matching manage_classes.php ── -->
        <div class="ss-header">
            <div class="ss-header-inner">
                <div class="ss-header-left">
                    <a href="<?= base_url('classes/view/') . urlencode(preg_replace('/^Class\s+/i', '', $class_name)) ?>"
                       class="ss-back-btn" title="Back to <?= htmlspecialchars($class_name) ?>">
                        <i class="fa fa-arrow-left"></i>
                    </a>
                    <div class="ss-header-icon">
                        <i class="fa fa-users" style="font-size:18px;color:#fff;"></i>
                    </div>
                    <div>
                        <h1 class="ss-title"><?= htmlspecialchars($class_name) ?></h1>
                        <p class="ss-subtitle">Section management &amp; timetable</p>
                    </div>
                </div>

                <div class="ss-header-right">
                    <!-- Section nav -->
                    <button id="prevSection" class="ss-nav-btn" title="Previous Section">
                        <i class="fa fa-chevron-left"></i>
                    </button>
                    <div class="ss-section-pill" id="ssSectionName">
                        <?= htmlspecialchars($section_name) ?>
                    </div>
                    <button id="nextSection" class="ss-nav-btn" title="Next Section">
                        <i class="fa fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>


        <!-- STUDENTS CARD -->
        <div class="students-card">
            <div class="students-card-header">
                <div>
                    <h2 class="mb-0" id="sectionMainTitle">Students</h2>
                    <h4 id="strengthWrapper">
                        <small style="color:var(--t3);font-family:var(--font-b);">
                            Total Strength:
                            <strong id="totalStrengthValue" style="color:var(--gold);">0</strong>
                        </small>
                    </h4>
                </div>

                <div class="students-actions">
                    <div class="search-box search-box-lg position-relative" id="studentSearchWrapper">
                        <input type="text" id="studentSearchInput" class="form-control"
                            placeholder="Search by name or ID">
                        <i class="fa fa-search search-icon"></i>
                    </div>

                    <!-- Back to sections list — shown only in timetable view -->
                    <a href="<?= base_url('classes/view/') . urlencode(preg_replace('/^Class\s+/i', '', $class_name)) ?>"
                       class="ss-back-to-class-btn" id="backToClassBtn" style="display:none;"
                       title="Back to <?= htmlspecialchars($class_name) ?> sections">
                        <i class="fa fa-th-large"></i> Sections
                    </a>

                    <button class="icon-btn" id="openSectionSettings" title="Section Settings">
                        <i class="fa fa-cog"></i>
                    </button>

                    <button class="btn ss-toggle-btn" id="toggleTimetableBtn" data-view="students">
                        <i class="fa fa-table"></i> Timetable
                    </button>
                </div>
            </div>

            <div id="sectionContent">
                <!-- Students OR Timetable loads here -->
            </div>
        </div>


        <!-- SECTION SETTINGS MODAL -->
        <div class="modal fade" id="sectionSettingsModal" tabindex="-1">
            <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content section-settings-modal">

                    <!-- HEADER -->
                    <div class="modal-header border-0">
                        <div class="header-wrap">
                            <div class="icon-wrap">
                                <i class="fa fa-users"></i>
                            </div>

                            <div>
                                <h5 class="modal-title">Section Strength</h5>
                                <p class="modal-subtitle">
                                    Define maximum allowed students
                                </p>
                            </div>
                        </div>

                        <button type="button" class="close close-btn" data-dismiss="modal">
                            ×
                        </button>
                    </div>

                    <!-- BODY -->
                    <div class="modal-body">
                        <label class="input-label">
                            Maximum Student Strength
                        </label>

                        <input type="number"
                            id="maxStrengthInput"
                            class="form-control strength-input"
                            min="1"
                            placeholder="100">
                    </div>

                    <!-- FOOTER -->
                    <div class="modal-footer">
                        <button class="btn btn-light btn-sm px-4" data-dismiss="modal">
                            Cancel
                        </button>

                        <button class="btn btn-warning btn-sm px-4" id="saveSectionSettings">
                            Save Changes
                        </button>
                    </div>

                </div>
            </div>
        </div>



        <div class="modal fade" id="transferStudentsModal" tabindex="-1">
            <div class="modal-dialog modal-md modal-dialog-centered">
                <div class="modal-content transfer-modal">

                    <!-- HEADER -->
                    <div class="modal-header  position-relative">
                        <h5 class="modal-title text-right w-100 pr-4">
                            <strong>
                                Transfer Students
                            </strong>
                        </h5>
                        <button class="close position-absolute" style="right: 15px;"
                            data-dismiss="modal">&times;</button>
                    </div>

                    <div class="modal-body">

                        <div class="transfer-flow">

                            <!-- FROM -->
                            <div class="transfer-box from-box">

                                <h6 class="text-muted mb-2">From</h6>

                                <div class="form-group mb-2">
                                    <label class="small">Class</label>
                                    <div class="form-control bg-light" readonly>
                                        <strong id="fromClassLabel"></strong>
                                    </div>
                                </div>

                                <div class="form-group mb-0">
                                    <label class="small">Section</label>
                                    <div class="form-control bg-light" readonly>
                                        <span id="fromSectionLabel"></span>
                                    </div>
                                </div>

                            </div>

                            <!-- ARROW -->
                            <div class="transfer-arrow">
                                <i class="fa fa-long-arrow-right"></i>
                            </div>

                            <!-- TO -->
                            <div class="transfer-box to-box">

                                <h6 class="text-muted mb-2">To</h6>

                                <div class="form-group mb-2">
                                    <label class="small">Target Class</label>
                                    <select id="targetClass" class="form-control"></select>
                                </div>

                                <div class="form-group mb-0">
                                    <label class="small">Target Section</label>
                                    <select id="targetSection" class="form-control"></select>
                                </div>

                            </div>

                        </div>

                        <p class="text-muted small mt-3 mb-0 text-center">
                            Selected students will be moved to the chosen class & section.
                        </p>

                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-secondary btn-sm" data-dismiss="modal">
                            Cancel
                        </button>
                        <button class="btn btn-danger btn-sm" id="confirmTransfer">
                            Confirm Transfer
                        </button>
                    </div>

                </div>
            </div>
        </div>





    </section>
</div>


<!-- jQuery must load before inline script (footer.php loads it too late) -->
<script src="<?= base_url() ?>tools/bower_components/jquery/dist/jquery.min.js"></script>

<script>
    /* ── CSRF ──────────────────────────────────────────────────────────────
     * Token sent TWO ways on every POST:
     *   1. Body field  [CSRF_NAME]  — satisfies CI's built-in csrf_protection filter
     *   2. Header      X-CSRF-Token — readable by MY_Controller.verify_csrf()
     * ─────────────────────────────────────────────────────────────────── */
    var CSRF_NAME = '<?php echo $this->security->get_csrf_token_name(); ?>';
    var CSRF_HASH = '<?php echo $this->security->get_csrf_hash(); ?>';

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    $.ajaxSetup({
        beforeSend: function (xhr) {
            xhr.setRequestHeader('X-CSRF-Token', CSRF_HASH);
        }
    });

    /* ============================================================
   GLOBAL STATE (SINGLE SOURCE OF TRUTH)
============================================================ */
    const CLASS_NAME = <?= json_encode($class_name) ?>;
    const SECTION_NAME = <?= json_encode($section_name) ?>;

    let CURRENT_VIEW = 'students';
    let ALL_SECTIONS = [];
    let CURRENT_INDEX = -1;

    let TIMETABLE_EDIT_MODE = false;
    let TIMETABLE_READY = false;
    let TIMETABLE_BACKUP = null;
    let ORIGINAL_SECTION_STRENGTH = null;

    /* Globals used across partials */
    window.CURRENT_CLASS_NAME = CLASS_NAME;
    window.CURRENT_SECTION_NAME = SECTION_NAME;
    window.ALL_SUBJECTS_CACHE = [];
    window.CURRENT_CELL = null;

    window.ignoreBlur = false;

    // 🔥 SUBJECTS USED IN TIMETABLE (SINGLE SOURCE OF TRUTH)
    window.SELECTED_SUBJECTS_SET = new Set();


    /* ============================================================
       INIT
    ============================================================ */
    $(document).ready(function() {
        fetchStudents();
        fetchSectionStrength();
        loadSectionsForNav();
    });

    /* ============================================================
       SECTION STRENGTH
    ============================================================ */
    function fetchSectionStrength() {
        var _d = { class_name: CLASS_NAME, section_name: SECTION_NAME };
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'classes/get_section_settings', _d,
            res => {
                $('#totalStrengthValue').text(
                    res && res.max_strength !== undefined ? res.max_strength : 0
                );
            },
            'json'
        );
    }


    /* ============================================================
   LOAD CURRENT SECTION STRENGTH INTO MODAL
============================================================ */
    function loadCurrentSectionStrength() {

        // Clear first (prevents stale values)
        $('#maxStrengthInput').val('').prop('disabled', true);

        var _d2 = { class_name: CLASS_NAME, section_name: SECTION_NAME };
        _d2[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'classes/get_section_settings', _d2,
            function(res) {

                if (res && res.max_strength !== undefined) {
                    ORIGINAL_SECTION_STRENGTH = res.max_strength;
                    $('#maxStrengthInput').val(res.max_strength);
                }

                // Enable + focus input
                $('#maxStrengthInput')
                    .prop('disabled', false)
                    .focus()
                    .select();

            },
            'json'
        );
    }
    $(document).on('input', '#maxStrengthInput', function() {
        const current = parseInt(this.value, 10);
        $('#saveSectionSettings').prop(
            'disabled',
            current === ORIGINAL_SECTION_STRENGTH
        );
    });



    /* ============================================================
       SECTION NAVIGATION
    ============================================================ */
    function loadSectionsForNav() {
        var _d = { class_name: CLASS_NAME };
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'classes/fetch_class_sections', _d,
            sections => {
                if (!Array.isArray(sections)) return;
                ALL_SECTIONS = sections.map(s => s.name);
                CURRENT_INDEX = ALL_SECTIONS.indexOf(SECTION_NAME);
                updateNavButtons();
            },
            'json'
        );
    }

    function updateNavButtons() {
        $('#prevSection').prop('disabled', CURRENT_INDEX <= 0);
        $('#nextSection').prop('disabled', CURRENT_INDEX >= ALL_SECTIONS.length - 1);
    }

    $('#prevSection').on('click', () => CURRENT_INDEX > 0 && navigateToIndex(CURRENT_INDEX - 1));
    $('#nextSection').on('click', () => CURRENT_INDEX < ALL_SECTIONS.length - 1 && navigateToIndex(CURRENT_INDEX + 1));

    function navigateToIndex(index) {
        if (!ALL_SECTIONS[index]) return;

        const cls = CLASS_NAME.replace('Class ', '');
        const sec = ALL_SECTIONS[index].replace('Section ', '');

        window.location.href =
            "<?= base_url('classes/section_students/') ?>" +
            encodeURIComponent(cls) + '/' +
            encodeURIComponent(sec);
    }

    /* ============================================================
       STUDENTS VIEW
    ============================================================ */
    function fetchStudents() {
        var _d = { class_name: CLASS_NAME, section_name: SECTION_NAME };
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'classes/fetch_section_students', _d,
            renderStudentsTable,
            'json'
        );
    }

    function renderStudentsTable(students) {
        if (!Array.isArray(students) || !students.length) {
            $('#sectionContent').html('<p class="text-muted">No students found.</p>');
            return;
        }

        let html = `
    <table class="table table-borderless students-table">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllStudents"></th>
                <th>S.No</th>
                <th>Student Photo</th>
                <th>Student Name</th>
                <th>Student ID</th>
                <th>Last result</th>
                <th>Phone No.</th>
                <th>View</th>
            </tr>
        </thead>
        <tbody>
    `;

        students.forEach((s, i) => {
            html += `
        <tr>
            <td>
                <input type="checkbox"
                       class="student-checkbox"
                       data-id="${s.id}">
            </td>
            <td>${i + 1}</td>
            <td>
                <img src="${s.photo || '<?= base_url("assets/avatar.png") ?>'}"
                     class="student-avatar">
            </td>
            <td>${esc(s.name)}</td>
            <td>${esc(s.id)}</td>
            <td>${esc(s.last_result)}</td>
            <td>${esc(s.phone)}</td>
            <td>
                <button class="btn btn-info btn-sm view-student"
                        data-id="${s.id}">
                    <i class="fa fa-eye"></i>
                </button>
            </td>
        </tr>
        `;
        });

        html += `
        </tbody>

        <tfoot>
            <tr>
                <td colspan="8">
                    <div class="d-flex justify-content-end pt-3 w-100">
                        <button class="btn btn-danger btn-lg"
                                id="transferStudentsBtn"
                                disabled>
                            Transfer Students
                            <i class="fa fa-exchange-alt"></i>
                        </button>
                    </div>
                </td>
            </tr>
        </tfoot>


    </table>
    `;

        $('#sectionContent').html(html);
        $('#selectAllStudents').prop('checked', false);
        updateTransferButtonState();

    }


    /* ============================================================
   STUDENT SEARCH (NAME / ID)
============================================================ */
    $(document).on('keyup', '#studentSearchInput', function() {

        const query = $(this).val().trim().toLowerCase();
        let visibleCount = 0;

        // Loop through student rows
        $('.students-table tbody tr').each(function() {

            const name = $(this).find('td:nth-child(4)').text().toLowerCase(); // Student Name
            const id = $(this).find('td:nth-child(5)').text().toLowerCase(); // Student ID

            if (!query || name.includes(query) || id.includes(query)) {
                $(this).show();
                visibleCount++;
            } else {
                $(this).hide();
            }
        });

        // Handle "no results"
        const $tbody = $('.students-table tbody');

        $tbody.find('.no-search-results').remove();

        if (query && visibleCount === 0) {
            $tbody.append(`
            <tr class="no-search-results">
                <td colspan="8" class="text-center text-muted py-3">
                    No matching students found
                </td>
            </tr>
        `);
        }
    });

    $(document).on('keydown', '#studentSearchInput', function(e) {
        if (e.key === 'Escape') {
            $(this).val('');
            $('.students-table tbody tr').show();
            $('.no-search-results').remove();
        }
    });




    $(document).on('click', '.view-student', function() {
        var studentId = $(this).data('id');
        window.location.href = "<?= base_url('student/student_profile') ?>/" + studentId;
    });


    function updateTransferButtonState() {
        const selectedCount = $('.student-checkbox:checked').length;

        $('#transferStudentsBtn').prop('disabled', selectedCount === 0);
    }



    $(document).on('change', '.student-checkbox', function() {

        const total = $('.student-checkbox').length;
        const checked = $('.student-checkbox:checked').length;

        // Sync "Select All" checkbox
        $('#selectAllStudents').prop('checked', total === checked);

        updateTransferButtonState();
    });

    $(document).on('change', '#selectAllStudents', function() {
        $('.student-checkbox').prop('checked', this.checked);
        updateTransferButtonState();
    });




    function loadClassesForTransfer() {
        var _d = {};
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'classes/loadClassesForTransfer', _d,
            function(res) {

                if (!res || typeof res !== 'object') return;

                const classes = res.classes || {};
                const sectionsMap = res.sections || {};

                const $classSelect = $('#targetClass').empty();
                const $sectionSelect = $('#targetSection').empty();

                // ✅ Filter valid classes (must have real sections)
                const validClasses = Object.keys(classes).filter(className => {
                    const sections = sectionsMap[className];
                    return Array.isArray(sections) &&
                        sections.length > 0 &&
                        sections.some(sec => sec.startsWith('Section '));
                });

                // Populate class dropdown (ONLY valid classes)
                validClasses.forEach(className => {
                    $classSelect.append(
                        `<option value="${className}">${className}</option>`
                    );
                });

                // Load sections for first valid class
                if (validClasses.length) {
                    populateSections(sectionsMap[validClasses[0]]);
                } else {
                    populateSections([]);
                }

                // On class change → reload sections
                $classSelect.off('change').on('change', function() {
                    populateSections(sectionsMap[this.value] || []);
                });
            },
            'json'
        );
    }


    function populateSections(sections) {

        const $sectionSelect = $('#targetSection').empty();

        if (!Array.isArray(sections) || !sections.length) {
            $sectionSelect.append(`<option value="">No Sections</option>`);
            return;
        }

        sections.forEach(sec => {
            $sectionSelect.append(
                `<option value="${sec}">${sec}</option>`
            );
        });
    }



    $(document).on('click', '#transferStudentsBtn', function() {

        if (this.disabled) return;

        $('#fromClassLabel').text(CLASS_NAME);
        $('#fromSectionLabel').text(SECTION_NAME);

        loadClassesForTransfer();
        $('#transferStudentsModal').modal('show');
    });


    function getSelectedStudentIds() {
        return $('.student-checkbox:checked')
            .map((i, el) => $(el).data('id'))
            .get();
    }


    $(document).on('click', '#confirmTransfer', function() {

        const $btn = $(this);
        if ($btn.prop('disabled')) return;

        const studentIds = $('.student-checkbox:checked')
            .map((i, el) => $(el).data('id'))
            .get();

        if (!studentIds.length) {
            alert('Please select at least one student.');
            return;
        }

        const payload = {
            student_ids: studentIds,
            from_class: CLASS_NAME,
            from_section: SECTION_NAME,
            to_class: $('#targetClass').val(),
            to_section: $('#targetSection').val()
        };
        payload[CSRF_NAME] = CSRF_HASH;

        // 🔒 Prevent double submit
        $btn.prop('disabled', true).text('Transferring...');

        $.post(
            BASE_URL + 'classes/transfer_students',
            payload,
            function(res) {

                if (res.status === 'success') {

                    $('#transferStudentsModal').modal('hide');

                    // Reset UI
                    $('.student-checkbox, #selectAllStudents').prop('checked', false);
                    updateTransferButtonState();

                    // Reload students of CURRENT section
                    fetchStudents();

                } else {
                    alert(res.message || 'Transfer failed.');
                }
            },
            'json'
        ).always(function() {
            $btn.prop('disabled', false).text('Confirm Transfer');
        });
    });



    /* ============================================================
       TOGGLE STUDENTS ↔ TIMETABLE
    ============================================================ */
    $(document).on('click', '#toggleTimetableBtn', function() {

        if (CURRENT_VIEW === 'students') {
            CURRENT_VIEW = 'timetable';
            $('#sectionMainTitle').text('Timetable');
            $('#strengthWrapper, #studentSearchWrapper').hide();
            $('#openSectionSettings').hide();
            $('#backToClassBtn').show();
            $(this).html('<i class="fa fa-users"></i> Student List');
            loadTimetable();
        } else {
            CURRENT_VIEW = 'students';
            $('#sectionMainTitle').text('Students');
            $('#strengthWrapper, #studentSearchWrapper').show();
            $('#studentSearchInput').val('');
            $('#openSectionSettings').show();
            $('#backToClassBtn').hide();
            $(this).html('<i class="fa fa-table"></i> Timetable');
            fetchStudents();
        }
    });

    /* ============================================================
       LOAD TIMETABLE
    ============================================================ */
    function loadTimetable() {
        TIMETABLE_READY = false;
        // Inline the timetable container HTML (no AJAX needed)
        $('#sectionContent').html(`
            <div class="timetable-wrapper">
                <div class="timetable-scroll">
                    <div class="timetable-grid" id="timetableGrid"></div>
                </div>
                <div class="timetable-footer">
                    <div id="timetableEditActions" class="hidden" style="gap:10px;">
                        <button class="btn btn-outline-secondary" id="cancelTimetableEdit">
                            <i class="fa fa-times"></i> Cancel
                        </button>
                        <button class="btn btn-success" id="saveTimetableEdit">
                            <i class="fa fa-save"></i> Save
                        </button>
                    </div>
                    <button class="btn btn-warning" id="editTimetableBtn">
                        <i class="fa fa-pencil"></i> Edit Timetable
                    </button>
                </div>
            </div>
            <div class="modal fade" id="subjectSelectModal" tabindex="-1">
                <div class="modal-dialog modal-xl modal-dialog-centered subject-dialog">
                    <div class="modal-content subject-modal">
                        <div class="modal-header subject-modal-header">
                            <button type="button" class="close subject-close" data-dismiss="modal">&times;</button>
                            <h4 class="modal-title">Subjects</h4>
                            <div class="subject-search-wrap" style="margin-top:12px;">
                                <input type="text" class="form-control subject-search-input"
                                    placeholder="Search subjects" id="subjectSearch">
                                <i class="fa fa-search subject-search-icon"></i>
                            </div>
                        </div>
                        <div class="modal-body subject-modal-body">
                            <h3 class="subject-section-title" id="classSubjectTitle"></h3>
                            <div class="subject-grid class-subjects"></div>
                            <h3 class="subject-section-title mt-3">All Subjects
                                <small class="text-muted" id="allSubjectCount"></small>
                            </h3>
                            <div class="subject-grid all-subjects"></div>
                        </div>
                    </div>
                </div>
            </div>
        `);
        fetchTimetableSettingsAndBuild();
    }

    function fetchTimetableSettingsAndBuild() {
        var _d = {};
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'academic/get_timetable_settings', _d,
            function(res) {
                if (!res || !res.success || !res.data) return;
                var s = res.data;
                if (!s.start_time || !s.end_time) return;
                // Map to the format buildTimetable expects
                buildTimetable({
                    Start_time: s.start_time,
                    End_time: s.end_time,
                    No_of_periods: s.no_of_periods,
                    Length_of_period: s.length_of_period,
                    Recesses: s.recesses
                });
                loadSavedTimetable();
            },
            'json'
        );
    }

    /* ============================================================
       BUILD & RENDER TIMETABLE
    ============================================================ */
    function buildTimetable(settings) {

        const start = ampmToMinutes(settings.Start_time);
        const periodLength = parseFloat(settings.Length_of_period);
        const totalPeriods = parseInt(settings.No_of_periods, 10);
        const recesses = Array.isArray(settings.Recesses) ?
            settings.Recesses : [];

        if (!start || !periodLength || !totalPeriods) {
            console.warn('Invalid timetable settings', settings);
            return;
        }

        let slots = [];
        let pointer = start;

        for (let p = 1; p <= totalPeriods; p++) {

            // PERIOD
            const next = Math.round(pointer + periodLength);

            slots.push({
                type: 'period',
                from: minutesToAMPM(pointer),
                to: minutesToAMPM(next)
            });

            pointer = next;

            // RECESS AFTER THIS PERIOD?
            const recess = recesses.find(r => r.after_period === p);

            if (recess && recess.duration > 0) {
                const breakEnd = pointer + recess.duration;

                slots.push({
                    type: 'break',
                    from: minutesToAMPM(pointer),
                    to: minutesToAMPM(breakEnd)
                });

                pointer = breakEnd;
            }
        }

        renderTimetable(slots);
        TIMETABLE_READY = true;
    }



    function renderTimetable(slots) {

        const $grid = $('#timetableGrid').empty();

        /* ===== HEADER ===== */
        let header = `
        <div class="tt-row tt-head">
            <div class="tt-cell day-time-head">
                <span class="day-label">Day</span>
                <span class="time-label">Time</span>
            </div>
    `;

        slots.forEach(s => {
            header += `
            <div class="tt-cell time-head ${s.type === 'break' ? 'break-head' : ''}">
                ${s.from} - ${s.to}
            </div>
        `;
        });

        header += `</div>`;
        $grid.append(header);

        /* ===== BODY ===== */
        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        days.forEach(day => {

            let row = `
            <div class="tt-row">
                <div class="tt-cell day">${day}</div>
        `;

            slots.forEach(s => {

                if (s.type === 'break') {
                    row += `<div class="tt-cell break-cell">BREAK</div>`;
                } else {
                    row += `<div class="tt-cell subject">Select subject</div>`;
                }

            });

            row += `</div>`;
            $grid.append(row);
        });
    }


    /* ============================================================
       LOAD & APPLY SAVED TIMETABLE
    ============================================================ */
    function loadSavedTimetable() {
        var _d = { class_name: CLASS_NAME, section_name: SECTION_NAME };
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'academic/get_section_timetable', _d,
            function(res) {
                if (res && res.success && res.data && TIMETABLE_READY) {
                    applySavedTimetable(res.data);
                }
            },
            'json'
        );
    }



    $(document).on('click', '#saveTimetableEdit', function() {

        if (!TIMETABLE_EDIT_MODE) return;

        const timetable = collectTimetableData();

        if (!Object.keys(timetable).length) {
            alert('Please assign at least one subject before saving.');
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true).text('Saving...');

        var _ttData = {
            class_name: CLASS_NAME,
            section_name: SECTION_NAME,
            timetable: JSON.stringify(timetable)
        };
        _ttData[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'academic/save_section_timetable', _ttData,
            function(res) {

                if (res && res.success) {

                    // Exit edit mode
                    TIMETABLE_EDIT_MODE = false;
                    TIMETABLE_BACKUP = null;
                    window.CURRENT_CELL = null;

                    $('#timetableEditActions').addClass('hidden');
                    $('#editTimetableBtn').removeClass('hidden');
                    $('.timetable-wrapper').removeClass('edit-mode');

                    // Reload saved timetable
                    loadSavedTimetable();

                } else {
                    alert((res && res.message) || 'Failed to save timetable');
                }
            },
            'json'
        ).always(function() {
            $btn.prop('disabled', false).text('Save');
        });
    });


    function calculatePeriodLength() {

        $('.period-skeleton').removeClass('hidden');
        $('#ttPeriodLength').val('');

        setTimeout(() => {

            const start = $('#ttStartTime').val();
            const end = $('#ttEndTime').val();
            const periods = parseInt($('#ttNoOfPeriod').val(), 10);

            if (!start || !end || !periods || periods <= 0) {
                $('.period-skeleton').addClass('hidden');
                return;
            }

            const startMin = toMinutes(start);
            const endMin = toMinutes(end);

            let recessTotal = 0;
            $('.recess-duration').each(function() {
                const d = parseInt(this.value, 10);
                if (d > 0) recessTotal += d;
            });

            const available = endMin - startMin - recessTotal;

            if (available <= 0) {
                alert('Recess time exceeds available duration');
                $('.period-skeleton').addClass('hidden');
                return;
            }

            const len = (available / periods).toFixed(1);
            $('#ttPeriodLength').val(len);

            $('.period-skeleton').addClass('hidden');

        }, 300);
    }


    $(document).on(
        'change',
        '#ttStartTime, #ttEndTime, #ttNoOfPeriod, .recess-after, .recess-duration',
        calculatePeriodLength
    );




    function toMinutes(t) {
        const [h, m] = t.split(':').map(Number);
        return (h * 60) + m;
    }




    $(document).on('click', '#cancelTimetableEdit', function() {

        // Restore previous timetable if backup exists
        if (TIMETABLE_BACKUP) {
            applySavedTimetable(TIMETABLE_BACKUP);
        }

        // Reset edit state cleanly
        TIMETABLE_EDIT_MODE = false;
        TIMETABLE_BACKUP = null;
        window.CURRENT_CELL = null;

        // Restore UI
        $('#timetableEditActions').addClass('hidden');
        $('#editTimetableBtn').removeClass('hidden');
        $('.timetable-wrapper').removeClass('edit-mode');
    });



    $(document).on('click', '#editTimetableBtn', function() {
        TIMETABLE_EDIT_MODE = true;
        TIMETABLE_BACKUP = collectTimetableData();

        $('#editTimetableBtn').addClass('hidden');
        $('#timetableEditActions').removeClass('hidden');
        $('.timetable-wrapper').addClass('edit-mode'); // 🔥 REQUIRED
    });



    $(document).on('click', '.tt-cell.subject', function() {
        if (!TIMETABLE_EDIT_MODE || $(this).hasClass('break-cell')) return;

        window.CURRENT_CELL = $(this);

        const existing = $(this).attr('data-subject-name');
        if (existing) {
            window.SELECTED_SUBJECTS_SET.add(existing);
        }

        loadSubjectsForTimetable();
        $('#subjectSelectModal').modal('show');
    });



    /* ============================================================
       SUBJECT SELECTION (REQUIRED FOR EDIT MODE)
    ============================================================ */

    function loadSubjectsForTimetable() {
        var _d = { class_name: CLASS_NAME };
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'academic/get_class_subjects', _d,
            function(res) {
                if (res && res.success && res.data) {
                    renderSubjectModal(res.data);
                }
            },
            'json'
        );
    }



    function renderSubjectModal(data) {

        if (!data || typeof data !== 'object') return;

        $('#classSubjectTitle').text(CURRENT_CLASS_NAME);

        window.ALL_SUBJECTS_CACHE = data.all_subjects || [];
        $('#allSubjectCount').text(`(${window.ALL_SUBJECTS_CACHE.length})`);

        const $classBox = $('.class-subjects').empty();

        if (Array.isArray(data.class_subjects) && data.class_subjects.length) {
            data.class_subjects.forEach(sub => {
                var label = esc(sub.name) + (sub.code && sub.code !== sub.name ? ' (' + esc(sub.code) + ')' : '');
                $classBox.append(`
                <button type="button"
                        class="subject-item outline class-subject"
                        data-name="${esc(sub.name)}">
                    ${label}
                </button>
            `);
            });
        } else {
            $classBox.html('<span class="text-muted">No class subjects</span>');
        }

        renderAllSubjects(window.ALL_SUBJECTS_CACHE);

        // 🔥 FIX: restore selected subject
        if (window.SELECTED_SUBJECT_NAME) {
            $('.subject-item').each(function() {
                const name = $(this).text().trim();
                if (window.SELECTED_SUBJECTS_SET.has(name)) {
                    $(this).addClass('selected');
                }
            });
        }
    }




    function renderAllSubjects(subjects) {

        const $grid = $('.all-subjects').empty();

        if (!Array.isArray(subjects) || !subjects.length) {
            $grid.html('<span class="text-muted">No subjects found</span>');
            return;
        }

        subjects.forEach(sub => {
            const isSelected = window.SELECTED_SUBJECTS_SET.has(sub.name);
            var label = esc(sub.name) + (sub.code && sub.code !== sub.name ? ' (' + esc(sub.code) + ')' : '');

            $grid.append(`
            <button type="button"
                class="subject-item outline ${isSelected ? 'selected' : ''}"
                data-name="${esc(sub.name)}">
                ${label}
            </button>
        `);
        });
    }



    function enterSearchMode() {
        $('.class-subjects').hide();
        $('.all-subjects').show();
    }

    function exitSearchMode() {
        $('.class-subjects').show();
    }

    // Click on search icon
    $(document).on('click', '.subject-search-icon', function() {
        enterSearchMode();
        renderAllSubjects(window.ALL_SUBJECTS_CACHE);
        $('#subjectSearch').focus();
    });

    // Focus search input
    $(document).on('focus', '#subjectSearch', function() {
        enterSearchMode();
        renderAllSubjects(window.ALL_SUBJECTS_CACHE);
    });

    // Typing search
    $(document).on('keyup', '#subjectSearch', function() {
        const q = $(this).val().trim().toLowerCase();

        if (!q) {
            exitSearchMode();
            renderAllSubjects(window.ALL_SUBJECTS_CACHE);
            return;
        }

        const filtered = window.ALL_SUBJECTS_CACHE.filter(sub =>
            sub.name.toLowerCase().includes(q)
        );

        renderAllSubjects(filtered);
    });

    // Prevent blur before click
    $(document).on('mousedown', '.subject-item', function() {
        window.ignoreBlur = true;
    });

    // Blur logic (restore state safely)
    $(document).on('blur', '#subjectSearch', function() {
        setTimeout(() => {
            // if (!window.ignoreBlur && !$('#subjectSearch').val().trim()) {
            if (!window.ignoreBlur && document.activeElement !== $('#subjectSearch')[0]) {
                exitSearchMode();
                renderAllSubjects(window.ALL_SUBJECTS_CACHE);
            }
            window.ignoreBlur = false;
        }, 150);
    });




    /* ============================================================
       SUBJECT PICK
    ============================================================ */
    $(document).on('click', '.subject-item', function() {
        if (!window.CURRENT_CELL) return;

        const name = $(this).text().trim();

        // ✅ APPLY TO TIMETABLE CELL
        window.CURRENT_CELL.text(name);
        window.CURRENT_CELL.attr('data-subject-name', name);

        // ✅ STORE IN GLOBAL SET
        window.SELECTED_SUBJECTS_SET.add(name);

        // ✅ MARK THIS BUTTON AS SELECTED
        $(this).addClass('selected');

        $('#subjectSelectModal').modal('hide');
    });





    /* ============================================================
       SETTINGS MODALS (SINGLE CONTROLLER)
    ============================================================ */
    $(document).on('click', '#openSectionSettings', function() {

        if (CURRENT_VIEW === 'students') {
            $('#sectionSettingsModal').modal('show');
            // fetchSectionStrength();
            loadCurrentSectionStrength();
            return;
        }

        $('#timetableSettingsModal').modal('show');
        fetchTimetableSettings();
    });

    /* ============================================================
       SAVE SECTION STRENGTH
    ============================================================ */
    $(document).on('click', '#saveSectionSettings', function() {

        const maxStrength = $('#maxStrengthInput').val();
        if (!maxStrength || maxStrength <= 0) return alert('Invalid value');

        var _d = {
            class_name: CLASS_NAME,
            section_name: SECTION_NAME,
            max_strength: maxStrength
        };
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            "<?= base_url('classes/save_section_settings') ?>", _d,
            res => {
                if (res.status === 'success') {
                    $('#sectionSettingsModal').modal('hide');
                    $('#totalStrengthValue').text(maxStrength);
                    ORIGINAL_SECTION_STRENGTH = null;
                }
            },
            'json'
        );
    });

    /* ============================================================
       TIMETABLE SETTINGS (FETCH + SAVE)
    ============================================================ */
    function fetchTimetableSettings() {
        var _d = {};
        _d[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'academic/get_timetable_settings', _d,
            function(res) {

                if (!res || !res.success || !res.data) return;
                var s = res.data;

                $('#ttStartTime').val(ampmTo24(s.start_time || ''));
                $('#ttEndTime').val(ampmTo24(s.end_time || ''));
                $('#ttNoOfPeriod').val(s.no_of_periods || '');
                $('#ttPeriodLength').val(s.length_of_period || '');

                $('#recessContainer').empty();

                if (Array.isArray(s.recesses)) {
                    s.recesses.forEach(r => {
                        addRecessRow(
                            r.after_period ?? '',
                            r.duration ?? ''
                        );
                    });
                }

                // recalc period length after load
                calculatePeriodLength();
            },
            'json'
        );
    }


    /* ============================================================
       RECESS HANDLERS
    ============================================================ */

    $(document).on('click', '#addRecessBtn', () => addRecessRow());

    $(document).on('click', '.removeRecessBtn', function() {
        $(this).closest('.recess-row').remove();
        calculatePeriodLength();
    });




    function addRecessRow(afterPeriod = '', duration = '') {

        const totalPeriods = parseInt($('#ttNoOfPeriod').val(), 10) || 0;

        let periodOptions = '<option value="">After period</option>';
        for (let i = 1; i < totalPeriods; i++) {
            periodOptions += `<option value="${i}">${i}</option>`;
        }

        const $row = $(`
        <div class="recess-row d-flex align-items-center gap-2 mb-2">
            <select class="form-control form-control-sm recess-after">
                ${periodOptions}
            </select>

            <input type="number"
                   class="form-control form-control-sm recess-duration"
                   placeholder="Duration (min)"
                   min="5">

            <button type="button"
                    class="btn btn-light btn-sm removeRecessBtn">✕</button>
        </div>
    `);

        // ✅ SET SAVED VALUES
        if (afterPeriod) {
            $row.find('.recess-after').val(String(afterPeriod));
        }

        if (duration) {
            $row.find('.recess-duration').val(duration);
        }

        $('#recessContainer').append($row);
    }



    $(document).on('click', '#saveTimetableSettings', function() {

        const recesses = [];

        $('.recess-row').each(function() {
            const after = parseInt($(this).find('.recess-after').val(), 10);
            const dur = parseInt($(this).find('.recess-duration').val(), 10);

            if (after && dur) {
                recesses.push({
                    after_period: after,
                    duration: dur
                });
            }
        });

        var _settData = {
            start_time: $('#ttStartTime').val(),
            end_time: $('#ttEndTime').val(),
            no_of_periods: $('#ttNoOfPeriod').val(),
            recesses: JSON.stringify(recesses)
        };
        _settData[CSRF_NAME] = CSRF_HASH;
        $.post(
            BASE_URL + 'academic/save_timetable_settings', _settData,
            function(res) {
                if (res && res.success) {
                    $('#timetableSettingsModal').modal('hide');
                    fetchTimetableSettingsAndBuild();
                } else {
                    alert((res && res.message) || 'Failed to save timetable');
                }
            },
            'json'
        );
    });



    function applySavedTimetable(saved) {

        if (!saved || typeof saved !== 'object') return;

        $('.tt-row:not(.tt-head)').each(function() {

            const day = $(this).find('.day').text().trim();
            const dayData = saved[day];
            if (!dayData) return;

            // Handle both formats:
            // New: {Monday: ["English", "Math", ...]} or [{subject:"English"}, ...]
            // Legacy: {Monday: [{time:"...", subject:"English"}, ...]}
            let isLegacy = Array.isArray(dayData) && dayData.length > 0 &&
                           typeof dayData[0] === 'object' && dayData[0] !== null && 'time' in dayData[0];

            if (isLegacy) {
                // Legacy format — match by time header
                const headerCells = $('.tt-head .tt-cell');
                const map = {};
                dayData.forEach(s => { if (s.time && s.subject) map[s.time] = s.subject; });

                $(this).find('.tt-cell').each(function(colIndex) {
                    if (colIndex === 0) return;
                    const time = $(headerCells[colIndex]).text().trim();
                    if (time && map[time] && map[time] !== 'BREAK') {
                        $(this).text(map[time]).attr('data-subject-name', map[time]);
                    }
                });
            } else {
                // New format — period-indexed array (skip break cells)
                let periodIdx = 0;
                $(this).find('.tt-cell').each(function(colIndex) {
                    if (colIndex === 0) return;
                    if ($(this).hasClass('break-cell')) return;

                    if (Array.isArray(dayData) && periodIdx < dayData.length) {
                        const cell = dayData[periodIdx];
                        const subject = (typeof cell === 'object' && cell !== null)
                            ? (cell.subject || '') : (cell || '');
                        if (subject && subject !== 'BREAK') {
                            $(this).text(subject).attr('data-subject-name', subject);
                        }
                    }
                    periodIdx++;
                });
            }
        });
    }


    function collectTimetableData() {

        const table = {};

        $('.tt-row:not(.tt-head)').each(function() {

            const day = $(this).find('.day').text().trim();
            const periods = [];

            $(this).find('.tt-cell').each(function(colIndex) {

                // skip day column (col 0)
                if (colIndex === 0) return;

                // skip break cells — breaks are derived from settings, not stored
                if ($(this).hasClass('break-cell')) return;

                const subject = $(this).text().trim();
                periods.push((subject && subject !== 'Select subject') ? subject : '');
            });

            if (periods.some(p => p !== '')) {
                table[day] = periods;
            }
        });

        return table;
    }

    function ampmToMinutes(t) {
        let [time, mod] = t.split(/(AM|PM)/);
        let [h, m] = time.split(':').map(Number);
        if (mod === 'PM' && h !== 12) h += 12;
        if (mod === 'AM' && h === 12) h = 0;
        return h * 60 + m;
    }

    function minutesToAMPM(m) {
        let h = Math.floor(m / 60),
            min = m % 60;
        let mod = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return `${h}:${String(min).padStart(2,'0')} ${mod}`;
    }

    function ampmTo24(t) {
        const m = t?.match(/(\d+):(\d+)(AM|PM)/);
        if (!m) return '';
        let h = +m[1];
        if (m[3] === 'PM' && h !== 12) h += 12;
        if (m[3] === 'AM' && h === 12) h = 0;
        return `${String(h).padStart(2,'0')}:${m[2]}`;
    }
</script>




<style>
    /* ── Page ─────────────────────────────────────── */
    .section-students-page { background: var(--bg); padding: 24px; }

    /* ── Gold header ─────────────────────────────── */
    .ss-header { background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%); border-radius: 14px; padding: 20px 24px; margin-bottom: 24px; }
    .ss-header-inner { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
    .ss-header-left  { display: flex; align-items: center; gap: 12px; }
    .ss-header-icon  { width: 40px; height: 40px; background: rgba(255,255,255,.18); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ss-title   { font: 700 20px/1.2 var(--font-b); color: #fff; margin: 0; }
    .ss-subtitle{ font: 400 12px/1.4 var(--font-b); color: rgba(255,255,255,.78); margin: 3px 0 0; }

    .ss-back-btn { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 9px; background: rgba(255,255,255,.20); color: #fff; transition: background var(--ease); flex-shrink: 0; text-decoration: none; }
    .ss-back-btn:hover { background: rgba(255,255,255,.35); color: #fff; }

    .ss-header-right { display: flex; align-items: center; gap: 8px; }
    .ss-nav-btn { width: 34px; height: 34px; border-radius: 50%; border: none; background: rgba(255,255,255,.20); color: #fff; font-size: 14px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background var(--ease); }
    .ss-nav-btn:hover:not(:disabled) { background: rgba(255,255,255,.38); }
    .ss-nav-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .ss-section-pill { background: rgba(255,255,255,.22); color: #fff; font: 700 14px/1 var(--font-b); padding: 8px 18px; border-radius: 24px; white-space: nowrap; }

    /* ── Students card ───────────────────────────── */
    .students-card { background: var(--bg2); border: 1px solid var(--border); border-radius: 14px; padding: 20px; box-shadow: var(--sh); }
    .students-card-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 18px; }
    .students-card-header h2 { font: 700 18px/1.2 var(--font-b); color: var(--t1); margin: 0; }
    .students-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

    .ss-toggle-btn { background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%); color: #fff; border: none; border-radius: 8px; padding: 8px 16px; font: 600 13px/1 var(--font-b); transition: opacity var(--ease); }
    .ss-toggle-btn:hover { opacity: .88; color: #fff; }
    .ss-back-to-class-btn { display: inline-flex; align-items: center; gap: 6px; background: var(--bg3); border: 1px solid var(--border); color: var(--t2); border-radius: 8px; padding: 7px 14px; font: 600 13px/1 var(--font-b); text-decoration: none; transition: all var(--ease); white-space: nowrap; }
    .ss-back-to-class-btn:hover { background: var(--gold-dim); color: var(--gold); border-color: var(--gold); text-decoration: none; }

    /* ── Search ──────────────────────────────────── */
    .search-box-lg { width: 260px; position: relative; }
    .search-box-lg input {
        height: 42px; font-size: 14px; padding-left: 14px; padding-right: 42px;
        border-radius: 22px; background: var(--bg); border: 1px solid var(--border); color: var(--t1);
    }
    .search-box-lg input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(15,118,110,.15); }
    .search-box-lg .search-icon { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); font-size: 16px; color: var(--t3); pointer-events: none; }

    .icon-btn { border: none; background: var(--gold); color: #fff; width: 42px; height: 42px; border-radius: 8px; font-size: 18px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: opacity var(--ease); }
    .icon-btn:hover { opacity: .85; }

    /* ── Students table ──────────────────────────── */
    .students-table thead { background: var(--gold-dim); }
    .students-table th { font-size: 13px; font-weight: 700; color: var(--t3); font-family: var(--font-b); }
    .students-table th:first-child, .students-table td:first-child { width: 36px; text-align: center; }
    .students-table td { color: var(--t2); vertical-align: middle; }
    .student-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }

    /* ── Pass/Fail ───────────────────────────────── */
    .result-box { width: 38px; height: 37px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: #fff; border-radius: 5px; text-transform: capitalize; }
    .result-box.pass { background-color: #3cb371; }
    .result-box.fail { background-color: #e04b4b; }

    /* ── Recess rows ─────────────────────────────── */
    .recess-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
    .recess-from, .recess-to {
        width: 110px; height: 30px; border-radius: 999px;
        border: 1px solid var(--border); background: var(--bg3);
        color: var(--t1); font-size: 12px; padding: 3px 10px;
    }
    .recess-from::-webkit-calendar-picker-indicator, .recess-to::-webkit-calendar-picker-indicator { opacity: 0.6; }
    .recess-label { font-size: 13px; color: var(--t3); }
    .removeRecessBtn {
        width: 22px; height: 22px; padding: 0; border-radius: 50%;
        border: 1px solid var(--border); background: var(--bg3);
        color: var(--t2); font-size: 12px; line-height: 1;
        display: flex; align-items: center; justify-content: center; cursor: pointer;
    }

    /* ── Timetable ───────────────────────────────── */
    .timetable-wrapper { background: var(--bg2); border: 1px solid var(--border); border-radius: 16px; padding: 24px; }
    .timetable-scroll { width: 100%; overflow-x: hidden; }
    .timetable-grid { display: flex; flex-direction: column; gap: 6px; }
    .tt-row { display: grid; grid-auto-flow: column; grid-template-columns: 110px repeat(auto-fit, minmax(75px, 1fr)); }
    .tt-cell { height: 38px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; white-space: nowrap; }
    .tt-head { position: sticky; top: 0; background: var(--bg2); z-index: 20; }
    .day { position: sticky; left: 0; z-index: 25; background: var(--bg3); color: var(--t2); font-weight: 600; }
    .time-head { background: var(--bg3); color: var(--t3); font-size: 11px; }
    .day-time-head { position: sticky; left: 0; z-index: 30; height: 38px; display: grid; grid-template-columns: 1fr 1fr; padding: 0; border-radius: 6px; overflow: hidden; font-weight: 600; width: 110px; justify-self: start; }
    .break-head { background: var(--gold) !important; color: #fff !important; font-weight: 700 !important; }
    .break-cell { background: var(--gold-dim) !important; color: var(--gold) !important; font-weight: 700; font-size: 10px; }
    .break-vertical { background: var(--gold-dim); color: var(--gold); font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; writing-mode: vertical-rl; transform: rotate(180deg); border-radius: 8px; }
    .subject { background: var(--bg3); color: var(--t2); }
    .timetable-wrapper.edit-mode .subject { cursor: pointer; outline: 2px dashed rgba(15,118,110,.6); }

    /* ── Subject select modal ────────────────────── */
    .subject-dialog { max-width: 960px; }
    .subject-modal { border-radius: 16px; border: 1px solid var(--border); background: var(--bg2); box-shadow: var(--sh); }
    .subject-modal-header { border-bottom: 1px solid var(--border); }
    .subject-modal-header .modal-title { font-size: 18px; font-weight: 700; color: var(--t1); font-family: var(--font-b); }
    .subject-search-input { border-radius: 20px; height: 34px; font-size: 12px; background: var(--bg); border: 1px solid var(--border); color: var(--t1); }
    .subject-grid { display: flex; flex-wrap: wrap; gap: 10px; }
    .subject-item {
        padding: 8px 14px; border-radius: 20px; font-size: 13px; cursor: pointer;
        background: var(--bg); border: 1px solid var(--border); color: var(--t2);
        font-family: var(--font-b); transition: transform var(--ease);
    }
    .subject-item:hover { transform: scale(1.05); border-color: var(--gold); color: var(--gold); }
    .subject-item.selected { background: var(--gold); border-color: var(--gold); color: #fff; font-weight: 600; }
    @media (max-width: 576px) { .subject-modal-body { padding: 16px; } }

    /* ── Transfer modal ──────────────────────────── */
    .transfer-flow { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .transfer-box { flex: 1; padding: 14px; border-radius: 8px; background: var(--bg); border: 1px solid var(--border); }
    .from-box { border-left: 4px solid var(--t3); }
    .to-box { border-left: 4px solid #dc3545; }
    .transfer-value { font-size: 15px; color: var(--t1); }
    .transfer-arrow { font-size: 32px; color: #dc3545; margin-top: 18px; }
    .transfer-modal .modal-body { padding: 20px; }

    /* ── Section settings modal ──────────────────── */
    .section-settings-modal { background: var(--bg2); border: 1px solid var(--border); border-radius: 20px; box-shadow: var(--sh); }
    .header-wrap { display: flex; align-items: center; gap: 14px; }
    .icon-wrap { width: 46px; height: 46px; border-radius: 14px; background: linear-gradient(135deg, var(--gold), #0d6b63); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; }
    .section-settings-modal .modal-title { font-size: 18px; font-weight: 700; margin: 0; color: var(--t1); font-family: var(--font-b); }
    .modal-subtitle { font-size: 13px; color: var(--t3); margin: 2px 0 0; font-family: var(--font-b); }
    .close-btn { font-size: 22px; opacity: 0.5; color: var(--t1); }
    .close-btn:hover { opacity: 1; }
    .section-settings-modal .modal-body { padding: 20px 18px 10px; }
    .input-label { font-size: 13px; font-weight: 600; margin-bottom: 6px; display: block; color: var(--t2); font-family: var(--font-b); }
    .strength-input { height: 46px; border-radius: 14px; font-size: 18px; font-weight: 600; text-align: center; border: 1px solid var(--border); background: var(--bg); color: var(--t1); }
    .strength-input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(15,118,110,.18); }
    .strength-input::placeholder { color: var(--t3); }
    .section-settings-modal .modal-footer { border-top: 1px solid var(--border); padding: 10px 18px 18px; display: flex; justify-content: flex-end; gap: 10px; }
</style>

<?php $this->load->view('partials/timetable_settings_modal'); ?>