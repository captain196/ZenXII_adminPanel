<!-- Timetable Settings Modal -->
<div class="modal fade" id="timetableSettingsModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content tt-modal">

      <div class="modal-header tt-modal-header">
        <h5 class="modal-title tt-modal-title">Time table Settings</h5>
        <button type="button" class="close tt-close" data-dismiss="modal">&times;</button>
      </div>

      <div class="modal-body tt-modal-body">
        <!-- Start Time -->
        <div class="form-group tt-field-row">
          <label class="tt-label">Start Time</label>
          <input type="time" id="ttStartTime" class="form-control tt-input-pill">
        </div>

        <!-- End Time -->
        <div class="form-group tt-field-row">
          <label class="tt-label">End Time</label>
          <input type="time" id="ttEndTime" class="form-control tt-input-pill">
        </div>

        
        <!-- Recess / Break -->
        <div class="form-group tt-field-row">
          <label class="tt-label mb-2">Recess / Break</label>

          <div id="recessContainer" class="recess-container"></div>

          <button type="button"
            class="btn tt-btn-recess mt-2"
            id="addRecessBtn">
            + Add Recess
          </button>
        </div>


        <!-- Length of a period -->
        <!-- Length of a period -->
        <div class="form-group tt-field-row">
          <label class="tt-label">Length of a period</label>

          <div class="period-length-wrapper">
            <input type="text"
              id="ttPeriodLength"
              class="form-control tt-input-pill"
              readonly>

            <!-- Skeleton loader -->
            <div class="period-skeleton hidden"></div>
          </div>
        </div>



        <div class="form-group tt-field-row">
          <label class="tt-label">No of periods</label>
          <input type="number" id="ttNoOfPeriod" class="form-control tt-input-pill">
        </div>

      </div>

      <div class="modal-footer tt-modal-footer">
        <button class="btn tt-btn-cancel" data-dismiss="modal">Cancel</button>
        <button class="btn tt-btn-save" id="saveTimetableSettings">Save</button>
      </div>

    </div>
  </div>
</div>



<style>
  .period-length-wrapper {
    position: relative;
  }

  .period-skeleton {
    position: absolute;
    top: 0;
    left: 0;
    height: 100%;
    width: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg,
        #e6e6e6 25%,
        #f5f5f5 37%,
        #e6e6e6 63%);
    background-size: 400% 100%;
    animation: skeleton-loading 1.2s ease-in-out infinite;
    z-index: 2;
  }

  .period-skeleton.hidden {
    display: none;
  }

  @keyframes skeleton-loading {
    0% {
      background-position: 100% 0;
    }

    100% {
      background-position: 0 0;
    }
  }

  /* ===== Modal look ===== */
  .tt-modal {
    border-radius: 18px;
    border: none;
    box-shadow: 0 18px 40px rgba(0, 0, 0, 0.18);
  }

  .tt-modal-header {
    border-bottom: none;
    padding: 16px 22px 6px;
  }

  .tt-modal-title {
    font-size: 20px;
    font-weight: 600;
    color: #222;
  }

  .tt-close {
    font-size: 22px;
    opacity: 0.5;
  }

  .tt-close:hover {
    opacity: 0.9;
  }

  .tt-modal-body {
    padding: 10px 32px 8px;
  }

  /* Center footer buttons */
  .tt-modal-footer {
    border-top: none;
    padding: 10px 32px 16px;
    display: flex;
    justify-content: center;
    gap: 10px;
  }

  /* Labels and right‑side controls on one line */
  .tt-field-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
  }

  .tt-label {
    margin: 0;
    font-size: 14px;
    color: #666;
  }

  /* Grey pill inputs (Start/End/Length) */
  .tt-input-pill {
    max-width: 170px;
    border-radius: 999px;
    border: 1px solid #e0e0e0;
    background-color: #f5f5f5;
    font-size: 13px;
    padding: 6px 14px;
    height: 34px;
  }

  /* ===== RECESS SECTION - Label LEFT, content RIGHT ===== */
  .tt-field-row:has(#recessContainer) {
    align-items: flex-start;
    gap: 16px;
  }

  .tt-field-row:has(#recessContainer) .tt-label {
    flex-shrink: 0;
    margin-bottom: 0;
  }

  /* Recess content wrapper - right aligned */
  .recess-container {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
    margin-left: auto;
    margin-top: 0;
  }

  /* Make sure + Add Recess stays right-aligned */
  #recessContainer+.tt-btn-recess {
    align-self: flex-end;
    margin-top: 2px;
  }

  /* Each recess row */
  .recess-row {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    flex-wrap: nowrap !important;
  }

  /* Recess time pills - smaller */
  .recess-from,
  .recess-to {
    width: 105px;
    height: 30px;
    border-radius: 999px;
    border: 1px solid #e0e0e0;
    background-color: #f5f5f5;
    font-size: 12px;
    padding: 3px 8px;
  }

  .recess-from::-webkit-calendar-picker-indicator,
  .recess-to::-webkit-calendar-picker-indicator {
    opacity: 0.6;
  }

  .recess-row span.text-muted {
    font-size: 13px;
    color: #555;
  }

  .removeRecessBtn {
    width: 22px;
    height: 22px;
    padding: 0;
    border-radius: 50%;
    border: 1px solid #e0e0e0;
    background-color: #f5f5f5;
    font-size: 12px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* + Add Recess button */
  .tt-btn-recess {
    background-color: #f5af00;
    color: #fff;
    border-radius: 4px;
    border: none;
    padding: 6px 14px;
    font-size: 13px;
    align-self: flex-start;
  }

  /* Footer buttons */
  .tt-btn-cancel,
  .tt-btn-save {
    min-width: 110px;
    font-size: 13px;
    padding: 6px 18px;
    border-radius: 4px;
  }

  .tt-btn-cancel {
    background-color: #f3f3f3;
    color: #777;
    border: 1px solid #ddd;
  }

  .tt-btn-save {
    background-color: #28a745;
    color: #fff;
    border: none;
  }
</style>