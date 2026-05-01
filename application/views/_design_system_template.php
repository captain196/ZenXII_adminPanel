<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
/* ============================================================================
   DESIGN SYSTEM REFERENCE TEMPLATE
   ----------------------------------------------------------------------------
   A canonical page using ONLY the global system in tools/css/style.css.
   Use this as the template when creating a new admin page, or as the
   target structure when migrating an existing module-prefixed view.

   Routes for live preview (add to application/config/routes.php if you want):
     $route['admin/design-system'] = 'Pages/design_system';
   Or just $this->load->view('_design_system_template') from any controller.
============================================================================ */
$this->load->view('include/header');
?>

<div class="content-wrapper">
    <section class="app-page">

        <!-- ── Page header ─────────────────────────────────────────────── -->
        <header class="app-page-header">
            <div class="app-page-header__lead">
                <span class="app-page-header__icon">
                    <i class="fa fa-rocket" aria-hidden="true"></i>
                </span>
                <div class="app-page-header__text">
                    <h1 class="app-page-header__title">Design System</h1>
                    <p class="app-page-header__subtitle">
                        Reference template — copy-paste components into any view
                    </p>
                </div>
            </div>
            <div class="app-page-header__actions">
                <a href="#" class="btn-app btn-app--secondary">
                    <i class="fa fa-download" aria-hidden="true"></i>
                    <span class="btn-app__label">Export</span>
                </a>
                <button type="button" class="btn-app btn-app--primary"
                        data-app-modal-open="ds_create_modal">
                    <i class="fa fa-plus" aria-hidden="true"></i>
                    <span class="btn-app__label">New record</span>
                    <span class="btn-app__spinner" aria-hidden="true"></span>
                </button>
            </div>
        </header>

        <!-- ── KPI strip ───────────────────────────────────────────────── -->
        <div class="row-app row-app--cols-4 u-mb-6">
            <div class="card-app">
                <div class="u-text-tertiary u-text-sm">Total students</div>
                <div class="u-mt-2" style="font-size:var(--fs-3xl);font-weight:700;font-family:var(--font-d);color:var(--t1)">1,248</div>
            </div>
            <div class="card-app">
                <div class="u-text-tertiary u-text-sm">Active fees</div>
                <div class="u-mt-2" style="font-size:var(--fs-3xl);font-weight:700;font-family:var(--font-d);color:var(--t1)">₹12.4L</div>
            </div>
            <div class="card-app">
                <div class="u-text-tertiary u-text-sm">Open tickets</div>
                <div class="u-mt-2" style="font-size:var(--fs-3xl);font-weight:700;font-family:var(--font-d);color:var(--t1)">7</div>
            </div>
            <div class="card-app">
                <div class="u-text-tertiary u-text-sm">Last sync</div>
                <div class="u-mt-2" style="font-size:var(--fs-3xl);font-weight:700;font-family:var(--font-d);color:var(--t1)">2 min</div>
            </div>
        </div>

        <!-- ── Card with table ─────────────────────────────────────────── -->
        <div class="card-app card-app--flush u-mb-6">
            <div class="card-app__head" style="padding:var(--space-4) var(--space-5);margin:0">
                <h2 class="card-app__title">Recent activity</h2>
                <div class="u-flex u-gap-2">
                    <span class="pill-app pill-app--success">12 active</span>
                    <span class="pill-app pill-app--warn">3 pending</span>
                </div>
            </div>
            <div class="table-app-wrap" style="border:0;border-radius:0;border-top:1px solid var(--border)">
                <table class="table-app">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Status</th>
                            <th style="text-align:right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>2026-04-26</td>
                            <td>Anita Rao</td>
                            <td>Fee receipt issued</td>
                            <td><span class="pill-app pill-app--success">Paid</span></td>
                            <td style="text-align:right">₹4,500</td>
                        </tr>
                        <tr>
                            <td>2026-04-26</td>
                            <td>Vikram S.</td>
                            <td>Refund processed</td>
                            <td><span class="pill-app pill-app--info">Refunded</span></td>
                            <td style="text-align:right">₹2,000</td>
                        </tr>
                        <tr>
                            <td>2026-04-25</td>
                            <td>Priya Mehra</td>
                            <td>Reminder sent</td>
                            <td><span class="pill-app pill-app--warn">Pending</span></td>
                            <td style="text-align:right">—</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Empty state demo (centered) ─────────────────────────────── -->
        <div class="card-app u-mb-6">
            <div class="empty-app">
                <div class="empty-app__icon">
                    <i class="fa fa-inbox" aria-hidden="true"></i>
                </div>
                <h3 class="empty-app__title">No reminders scheduled</h3>
                <p class="empty-app__msg">
                    When you set up automated fee reminders, they'll appear here so you can review or pause them.
                </p>
                <button type="button" class="btn-app btn-app--primary empty-app__cta"
                        data-app-modal-open="ds_create_modal">
                    <i class="fa fa-plus" aria-hidden="true"></i>
                    <span class="btn-app__label">Create reminder</span>
                </button>
            </div>
        </div>

        <!-- ── Form demo ───────────────────────────────────────────────── -->
        <div class="card-app">
            <div class="card-app__head">
                <h2 class="card-app__title">Form example</h2>
            </div>

            <form data-no-loading onsubmit="event.preventDefault();AppBtn.start(this.querySelector('button[type=submit]'));setTimeout(()=>AppBtn.stop(this.querySelector('button[type=submit]')),1500);return false;">
                <div class="form-app__grid">
                    <div class="form-app__row">
                        <label class="form-app__label form-app__label--required">Student name</label>
                        <input type="text" class="form-app__control" placeholder="e.g. Anita Rao">
                        <span class="form-app__hint">Full name as registered.</span>
                    </div>
                    <div class="form-app__row">
                        <label class="form-app__label">Class</label>
                        <select class="form-app__control">
                            <option>Class 8 - A</option>
                            <option>Class 8 - B</option>
                            <option>Class 9 - A</option>
                        </select>
                    </div>
                    <div class="form-app__row">
                        <label class="form-app__label">Roll number</label>
                        <input type="text" class="form-app__control" placeholder="0142">
                    </div>
                </div>

                <div class="form-app__row">
                    <label class="form-app__label">Notes</label>
                    <textarea class="form-app__control" placeholder="Optional notes…"></textarea>
                </div>

                <div class="u-flex u-justify-end u-gap-2 u-mt-4">
                    <button type="button" class="btn-app btn-app--ghost">Cancel</button>
                    <button type="submit" class="btn-app btn-app--primary">
                        <span class="btn-app__label">Save changes</span>
                        <span class="btn-app__spinner" aria-hidden="true"></span>
                    </button>
                </div>
            </form>
        </div>

    </section>
</div>

<!-- ── Modal — centered, opens from header CTA + empty state CTA ───────── -->
<div id="ds_create_modal" class="modal-app__backdrop" role="dialog" aria-modal="true" aria-labelledby="ds_create_modal_title">
    <div class="modal-app modal-app--md">
        <header class="modal-app__header">
            <h2 class="modal-app__title" id="ds_create_modal_title">Create record</h2>
            <button type="button" class="modal-app__close" data-app-modal-close aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </header>

        <form id="ds_create_form" onsubmit="event.preventDefault();AppBtn.start(this.querySelector('button[type=submit]'));setTimeout(()=>{AppBtn.stop(this.querySelector('button[type=submit]'));AppModal.close(document.getElementById('ds_create_modal'));},900);">
            <div class="modal-app__body">
                <div class="form-app__row">
                    <label class="form-app__label form-app__label--required">Title</label>
                    <input type="text" class="form-app__control" placeholder="Enter a title…" required>
                </div>
                <div class="form-app__row">
                    <label class="form-app__label">Description</label>
                    <textarea class="form-app__control" rows="3" placeholder="Optional…"></textarea>
                </div>
            </div>
            <footer class="modal-app__footer">
                <button type="button" class="btn-app btn-app--ghost" data-app-modal-close>Cancel</button>
                <button type="submit" class="btn-app btn-app--primary">
                    <span class="btn-app__label">Create</span>
                    <span class="btn-app__spinner" aria-hidden="true"></span>
                </button>
            </footer>
        </form>
    </div>
</div>

<?php $this->load->view('include/footer'); ?>
