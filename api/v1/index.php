<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="stylesheet" href="assets/css/style.css" />
  <meta charset="UTF-8" />
  <title>Garage Maintenance</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  

  <link
    rel="stylesheet"
    href="https://code.jquery.com/ui/1.13.3/themes/base/jquery-ui.css"
  />
</head>
<body>

  <div id="db-error-banner" style="display:none;background:#7f1d1d;color:#fff;padding:12px 16px;font-weight:600;">
    âš  Database connection failed. Check your config.php credentials.
  </div>

  <div class="app">
    <header>
      <div class="title-block">
        <h1 id="site-title">Garage Maintenance</h1>
        <span>Per-vehicle service history, reminders & upcoming maintenance</span>
      </div>
      <div class="top-controls">
        <div class="nav">
          <button class="nav-btn active" data-view="dashboard">ðŸ“Š Dashboard</button>
          <button class="nav-btn" data-view="reminders">ðŸ”” Reminders</button>
          <button class="nav-btn" data-view="settings">âš™ï¸ Settings</button>
        </div>
        <div class="vehicle-picker" id="vehicle-picker">
  <div class="vehicle-picker-select-row">
    <span>Vehicle:</span>
    <select id="active-vehicle"></select>
  </div>
  <div id="vehicle-picker-odo"></div>
</div>
      </div>
    </header>

    <main>
      <!-- DASHBOARD -->
    <section id="view-dashboard" class="view active">
  <!-- Toggle button for entry form -->
  <div class="dashboard-actions">
    <button type="button" class="btn-primary" id="toggle-entry-form">
      <span id="toggle-form-icon">+</span> Add New Service Entry
    </button>
  </div>

  <!-- Entry form (starts hidden) -->
  <section class="card dashboard-entry-form" id="dashboard-entry-form" style="display: none;">
    <div class="card-header">
      <h2>New Service Entry</h2>
      <small>For the selected vehicle</small>
    </div>

    <form id="entry-form">
      <input type="hidden" id="entry-id" />
      <div class="field-grid">
        <div class="field">
          <label for="entry-date">Service date</label>
          <input
            id="entry-date"
            type="text"
            placeholder="YYYY-MM-DD"
            autocomplete="off"
            required
          />
        </div>
        <div class="field">
          <label for="entry-odo">
            Odometer (<span class="unit-label">mi</span>)
          </label>
          <input id="entry-odo" type="number" min="0" step="1" />
        </div>
        <div class="field" style="grid-column: 1 / -1;">
          <label>Services performed</label>
          <div class="services-scroll">
            <div id="service-checklist" class="service-checklist">
            </div>
          </div>
          <input
            id="entry-services-other"
            type="text"
            placeholder="Other/custom (comma or ; separated)"
          />
        </div>
        <div class="field">
          <label for="entry-cost">Cost (optional)</label>
          <input id="entry-cost" type="number" min="0" step="0.01" />
        </div>
        <div class="field">
          <label for="entry-next-date">
            Next due date <span class="text-muted">(optional)</span>
          </label>
          <input
            id="entry-next-date"
            type="text"
            placeholder="YYYY-MM-DD"
            autocomplete="off"
          />
        </div>
        <div class="field">
          <label for="entry-next-odo">
            Next due mileage (<span class="unit-label">mi</span>)
          </label>
          <input id="entry-next-odo" type="number" min="0" step="1" />
        </div>
      </div>

      <div class="field" style="margin-top:6px;">
        <label for="entry-notes">Notes</label>
        <textarea
          id="entry-notes"
          placeholder="Shop name, parts, oil weight, torque specs, battery model, etc."
        ></textarea>
      </div>

      <div class="field" style="margin-top:6px;">
        <label for="entry-files">Attachments (optional)</label>
        <input id="entry-files" type="file" multiple accept=".pdf,.doc,.docx,image/*" />
        <div class="text-muted" style="font-size:0.7rem;">
          <span id="entry-attach-limit-text">Attachments: PDF, Word, and image files only. Limits apply per entry.</span>
        </div>
      </div>

      <div class="button-row">
        <button type="button" class="btn-ghost btn-small" id="entry-reset">
          Clear
        </button>
        <button type="submit" class="btn-primary">
          <span id="entry-submit-label">Save entry</span>
        </button>
      </div>
      
      <!-- User preference for keeping form open -->
      <div class="form-preference-section">
        <label class="form-preference-toggle">
          <input type="checkbox" id="keep-form-open-pref" />
          <span>Keep form open after adding entry</span>
        </label>
      </div>
    </form>
  </section>

  <!-- History and reminders now full width -->
  <div class="dashboard-content">
    <section class="card">
      <div class="card-header">
        <h2>Vehicle Overview & History</h2>
        <small id="overview-vehicle-label"></small>
      </div>

      <div class="stats-row">
        <div class="pill">
          <span class="pill-dot"></span>
          <span><strong id="history-total">0</strong> entries</span>
        </div>
      </div>
      
      <div class="safety-status-row" id="safety-status-container" style="display:none;">
  <div class="safety-status">
    <span class="safety-icon">ðŸ”’</span>
    <span class="safety-label">Safety:</span>
    <span id="safety-status-badge" class="safety-badge">â€”</span>
    <button type="button" class="btn-ghost btn-small" id="check-recalls-btn">
      Check Recalls
    </button>
  </div>
</div>

<!-- Recall Details Modal (hidden by default) -->
<div id="recall-modal" class="recall-modal" style="display:none;">
  <div class="recall-modal-overlay"></div>
  <div class="recall-modal-content">
    <div class="recall-modal-header">
      <h3>Safety Recalls</h3>
      <button type="button" class="recall-modal-close" id="close-recall-modal">Ã—</button>
    </div>
    <div class="recall-modal-body" id="recall-modal-body">
      <!-- Content inserted by JavaScript -->
    </div>
  </div>
</div>

      <div id="entry-list" class="entry-list">
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h2>Maintenance Reminders (Quick View)</h2>
        <small>Upcoming & overdue for this vehicle</small>
      </div>
      <div class="stats-row">
        <div class="pill">
          <span class="pill-dot warn"></span>
          <span><strong id="rem-snippet-upcoming">0</strong> upcoming</span>
        </div>
        <div class="pill">
          <span class="pill-dot bad"></span>
          <span><strong id="rem-snippet-overdue">0</strong> overdue</span>
        </div>
      </div>
      <div id="reminder-snippet-list" class="reminder-snippet-list">
      </div>
    </section>
  </div>
</section>

      <!-- REMINDERS -->
      <section id="view-reminders" class="view">
        <section class="card">
          <div class="card-header">
            <h2>Reminders Overview</h2>
            <small>
              Per-vehicle maintenance reminders based on intervals, last service & current mileage.
            </small>
          </div>

          <div class="stats-row">
            <div class="pill">
              <span class="pill-dot"></span>
              <span><strong id="rem-total">0</strong> reminders</span>
            </div>
            <div class="pill">
              <span class="pill-dot warn"></span>
              <span><strong id="rem-upcoming">0</strong> upcoming</span>
            </div>
            <div class="pill">
              <span class="pill-dot bad"></span>
              <span><strong id="rem-overdue">0</strong> overdue</span>
            </div>
          </div>

          <div class="settings-help" style="margin-bottom:6px;">
            Each reminder can use mileage and/or time intervals. Status shows
            <strong>â€œdue in X <span class="unit-label">mi</span> or Y days, whichever comes first.â€</strong>
          </div>

          <div class="reminders-list" id="reminders-list">
          </div>

          <div class="settings-section" style="margin-top:10px;">
            <h3>Add new reminder</h3>
            <div class="settings-help">
              For the currently selected vehicle. Service name can be chosen from your
              service types or typed manually. Intervals default from the per-vehicle
              template when available.
            </div>
            <form id="reminder-form">
              <div class="field-grid">
                <div class="field">
                  <label for="rem-new-service">Service name</label>
                  <select id="rem-new-service"></select>
                  <input
                    id="rem-new-service-custom"
                    type="text"
                    placeholder="Custom service name (optional)"
                    style="margin-top:4px;"
                  />
                </div>
                <div class="field">
                  <label for="rem-new-interval-miles">
                    Interval (<span class="unit-label">mi</span>, optional)
                  </label>
                  <input id="rem-new-interval-miles" type="number" min="0" step="100" />
                </div>
                <div class="field">
                  <label for="rem-new-interval-months">Interval (months, optional)</label>
                  <input id="rem-new-interval-months" type="number" min="0" step="1" />
                </div>
                <div class="field">
                  <label for="rem-new-notes">Notes (optional)</label>
                  <input
                    id="rem-new-notes"
                    type="text"
                    placeholder="Any extra info or link"
                  />
                </div>
              </div>
              <div class="button-row" style="justify-content:flex-end;">
                <button type="submit" class="btn-primary btn-small">
                  + Add reminder
                </button>
              </div>
            </form>
          </div>
        </section>
      </section>

      <!-- SETTINGS -->
      <section id="view-settings" class="view">
        <section class="card">
          <div class="card-header">
            <h2>Settings</h2>
            <small>General, vehicles, service types, intervals, backup & export</small>
          </div>

          <div class="settings-tabs">
            <button class="settings-tab-btn active" data-tab="general">General</button>
            <button class="settings-tab-btn" data-tab="vehicles">Vehicles</button>
            <button class="settings-tab-btn" data-tab="services">Service types</button>
            <button class="settings-tab-btn" data-tab="backup">Backup & Export</button>
          </div>

          <div class="settings-tabs-content">
            <!-- Tab: General -->
            <div id="settings-tab-general" class="settings-tab-view active">
              <div class="settings-section">
                <h3>General</h3>
                <div class="settings-help">
                  Change the site title, distance units, and timezone. Unit affects labels and reminder
                  text (mi vs km) but does not convert stored values.
                </div>
                <div class="field-grid">
                  <div class="field">
                    <label for="settings-site-title">Site title</label>
                    <input
                      type="text"
                      id="settings-site-title"
                      placeholder="Garage Maintenance"
                    />
                  </div>
                  <div class="field">
                    <label for="settings-unit">Distance unit</label>
                    <select id="settings-unit">
                      <option value="mi">Miles</option>
                      <option value="km">Kilometers</option>
                    </select>
                  </div>
                  <div class="field">
                    <label for="settings-timezone">Timezone</label>
                    <select id="settings-timezone">
                      <option value="">Use browser default</option>
                      <option value="Pacific/Honolulu">US - Hawaii (HST)</option>
                      <option value="America/Anchorage">US - Alaska (AKST)</option>
                      <option value="America/Los_Angeles">US - Pacific (PT)</option>
                      <option value="America/Denver">US - Mountain (MT)</option>
                      <option value="America/Chicago">US - Central (CT)</option>
                      <option value="America/New_York">US - Eastern (ET)</option>
                      <option value="Europe/London">Europe - London</option>
                      <option value="Europe/Berlin">Europe - Central</option>
                      <option value="Asia/Tokyo">Asia - Tokyo</option>
                      <option value="Asia/Singapore">Asia - Singapore</option>
                      <option value="Australia/Sydney">Australia - Sydney</option>
                    </select>
                  </div>
                </div>
                
                <!-- Reminder Threshold Settings -->
                <div class="settings-section" style="margin-top:16px;">
                  <h3>Reminder Thresholds</h3>
                  <div class="settings-help">
                    Configure when reminders are marked as "upcoming" or "overdue". 
                    Reminders within these thresholds will be highlighted accordingly.
                  </div>
                  <div class="field-grid">
                    <div class="field">
                      <label for="settings-upcoming-days">Upcoming threshold (days)</label>
                      <input
                        type="number"
                        id="settings-upcoming-days"
                        min="1"
                        max="365"
                        placeholder="14"
                      />
                      <small class="text-muted">Mark as "upcoming" when due within this many days</small>
                    </div>
                    <div class="field">
                      <label for="settings-upcoming-miles">Upcoming threshold (<span class="unit-label">mi</span>)</label>
                      <input
                        type="number"
                        id="settings-upcoming-miles"
                        min="100"
                        step="100"
                        placeholder="500"
                      />
                      <small class="text-muted">Mark as "upcoming" when due within this distance</small>
                    </div>
                    <div class="field">
                      <label for="settings-overdue-days">Overdue grace period (days)</label>
                      <input
                        type="number"
                        id="settings-overdue-days"
                        min="0"
                        max="365"
                        placeholder="0"
                      />
                      <small class="text-muted">Mark as "overdue" after past due by this many days (0 = immediately)</small>
                    </div>
                    <div class="field">
                      <label for="settings-overdue-miles">Overdue grace period (<span class="unit-label">mi</span>)</label>
                      <input
                        type="number"
                        id="settings-overdue-miles"
                        min="0"
                        step="100"
                        placeholder="0"
                      />
                      <small class="text-muted">Mark as "overdue" after past due by this distance (0 = immediately)</small>
                    </div>
                  </div>
                </div>
                
                <div class="button-row" style="justify-content:flex-start;margin-top:8px;">
                  <button type="button" class="btn-primary btn-small" id="settings-general-save">
                    Save general settings
                  </button>
                </div>
              </div>
            </div>

            <!-- Tab: Vehicles -->
            <div id="settings-tab-vehicles" class="settings-tab-view">
              <div class="settings-section">
                <h3>Vehicles</h3>
                <div class="settings-help">
                  Vehicles appear in the selector at the top. Set
                  <strong>current mileage</strong> for mileage-based reminders. VIN and plate
                  are optional and will be included in exports (in the header).
                </div>
                <div id="settings-vehicles" class="settings-list"></div>
                <div class="settings-add-row">
                  <input
                    type="text"
                    id="settings-vehicle-new"
                    placeholder="Add new vehicle (e.g. 2018 Mazda CX-5)"
                  />
                  <button type="button" class="btn-primary btn-small" id="settings-vehicle-add">
                    + Add vehicle
                  </button>
                </div>
              </div>
            </div>

            <!-- Tab: Service types -->
            <div id="settings-tab-services" class="settings-tab-view">
              <div class="settings-section">
                <h3>Service types</h3>
                <div class="settings-help">
                  These appear as checkboxes when documenting a service and as templates for
                  reminders. Service types are universal; their default intervals apply to all vehicles.
                </div>
                <div id="settings-services" class="settings-list"></div>
                <div class="settings-add-row">
                  <input
                    type="text"
                    id="settings-service-new"
                    placeholder="Add service type (e.g. Oil change)"
                  />
                  <button type="button" class="btn-primary btn-small" id="settings-service-add">
                    + Add service
                  </button>
                </div>
              </div>
            </div>

            <!-- Tab: Backup & Export -->
            <!-- 
  REPLACE the backup section in index.php (inside settings-tab-backup div)
  This replaces lines approximately 407-480 in the original file
-->

            <!-- Tab: Backup & Export -->
            <div id="settings-tab-backup" class="settings-tab-view">

<div class="settings-section">
  <h3>Backup & export</h3>
  <div class="settings-help">
    <strong>Full backup (JSON):</strong> Includes all data AND attachment files embedded in a single JSON file. Best for complete backup/restore.
    <br>
    <strong>Data only (JSON):</strong> Database only, no attachments. Smaller file, faster for data migration.
    <br>
    <strong>Table exports:</strong> Export service history for the currently selected vehicle as Excel/CSV, Word, or PDF.
  </div>
  
  <!-- Full Backup Section -->
  <div style="margin-top:10px; padding:10px; border-radius:8px; background:rgba(56,189,248,0.08); border:1px solid rgba(56,189,248,0.3);">
    <div style="font-weight:600; margin-bottom:6px; color:#38bdf8;">ðŸ—œï¸ Complete Backup (Recommended)</div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">
      Includes all data + attachment files in a single JSON file
    </div>
    <div class="button-row" style="justify-content:flex-start; margin-top:4px;">
      <button type="button" class="btn-primary btn-small" id="backup-export-full">
        â¬‡ï¸ Download Full Backup
      </button>
      <label class="btn-primary btn-small" style="cursor:pointer;">
        â¬†ï¸ Restore from Full Backup
        <input
          type="file"
          id="backup-import-full"
          accept=".json,.zip,application/json,application/zip"
          style="display:none;"
        />
      </label>
    </div>
  </div>
  
  <!-- Data-Only Backup Section -->
  <div style="margin-top:10px; padding:10px; border-radius:8px; background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.3);">
    <div style="font-weight:600; margin-bottom:6px;">ðŸ“„ Data Only Backup</div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">
      Database only (no attachment files). Smaller file size.
    </div>
    <div class="button-row" style="justify-content:flex-start; margin-top:4px;">
      <button type="button" class="btn-ghost btn-small" id="backup-export">
        â¬‡ï¸ Export data (JSON)
      </button>
      <label class="btn-ghost btn-small" style="cursor:pointer;">
        â¬†ï¸ Import data (JSON)
        <input
          type="file"
          id="backup-import"
          accept=".json,.txt,application/json"
          style="display:none;"
        />
      </label>
    </div>
  </div>
  
  <!-- Table Export Section -->
  <div style="margin-top:10px; padding:10px; border-radius:8px; background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.3);">
    <div style="font-weight:600; margin-bottom:6px;">ðŸ“Š Table Export (Current Vehicle)</div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">
      Export service history table for the currently selected vehicle. Vehicle name, VIN and plate are included in the header.
    </div>
    <div class="button-row" style="justify-content:flex-start; margin-top:4px;">
      <button type="button" class="btn-ghost btn-small" id="export-excel">
        ðŸ“Š Export table (Excel/CSV)
      </button>
      <button type="button" class="btn-ghost btn-small" id="export-word">
        ðŸ“„ Export table (Word)
      </button>
      <button type="button" class="btn-ghost btn-small" id="export-pdf">
        ðŸ“• Export table (PDF)
      </button>
    </div>
  </div>
  
  <!-- Danger Zone -->
  <div style="margin-top:10px; padding:10px; border-radius:8px; background:rgba(248,113,113,0.08); border:1px solid rgba(248,113,113,0.3);">
    <div style="font-weight:600; margin-bottom:6px; color:#f97373;">âš ï¸ Danger Zone</div>
    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:8px;">
      This will permanently delete all data and attachments. Cannot be undone!
    </div>
    <button type="button" class="btn-danger btn-small" id="backup-reset">
      ðŸ—‘ï¸ Clear all data
    </button>
  </div>
</div>
            </div>
          </div>
        </section>
      </section>
    </main>

    <footer>
      <span>Â© 2025 Garage Maintenance. All rights reserved.</span>
      <span>Prototype Version 1.9</span>
    </footer>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://code.jquery.com/ui/1.13.3/jquery-ui.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.1/jspdf.plugin.autotable.min.js"></script>

  

  <script src="assets/js/app.js"></script>
</body>
</html>
