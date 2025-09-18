    </main> <!-- end main-content -->

    <footer class="footer-fixed">
        <p class="mb-0 text-center text-muted">&copy; <?= date('Y') ?> Config Manager v<?= APP_VERSION ?> | <a href="<?= base_url('/changelog') ?>">Changelog</a></p>
    </footer>

</div> <!-- end content-wrapper -->

<!-- Stack Change Detail Modal -->
<div class="modal fade" id="stackChangeDetailModal" tabindex="-1" aria-labelledby="stackChangeDetailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="stackChangeDetailModalLabel">Stack Change Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <dl class="row">
          <dt class="col-sm-3">Stack Name</dt>
          <dd class="col-sm-9" id="detail-stack-name"></dd>
          <dt class="col-sm-3">Change Type</dt>
          <dd class="col-sm-9" id="detail-change-type"></dd>
          <dt class="col-sm-3">Timestamp</dt>
          <dd class="col-sm-9" id="detail-created-at"></dd>
          <dt class="col-sm-3">Changed By</dt>
          <dd class="col-sm-9" id="detail-changed-by"></dd>
          <dt class="col-sm-3">Details</dt>
          <dd class="col-sm-9"><pre><code id="detail-details" class="font-monospace"></code></pre></dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toast-container" style="z-index: 1100">
    <!-- Toasts will be appended here by JavaScript -->
</div>

<!-- View History Modal -->
<div class="modal fade" id="viewHistoryModal" tabindex="-1" aria-labelledby="viewHistoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewHistoryModalLabel">View YAML Configuration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre><code id="yaml-content-container" class="language-yaml">Loading...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Container Logs Modal -->
<div class="modal fade" id="viewLogsModal" tabindex="-1" aria-labelledby="viewLogsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="viewLogsModalLabel">Container Logs</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body bg-dark text-light font-monospace">
        <pre><code id="log-content-container">Loading logs...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Network Modal -->
<div class="modal fade" id="networkModal" tabindex="-1" aria-labelledby="networkModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="networkModalLabel">Add New Network</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="network-form">
            <input type="hidden" name="action" value="create">
            <div class="mb-3">
                <label for="network-name" class="form-label">Network Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="network-name" name="name" required>
            </div>
            <div class="mb-3">
                <label for="network-driver" class="form-label">Driver</label>
                <select class="form-select" id="network-driver" name="driver">
                    <option value="bridge" selected>bridge</option>
                    <option value="overlay">overlay</option>
                    <option value="macvlan">macvlan</option>
                    <option value="host">host</option>
                    <option value="none">none</option>
                </select>
            </div>
            <div id="network-macvlan-container" style="display: none;">
                <hr>
                <h6>MACVLAN Options</h6>
                <div class="mb-3">
                    <label for="macvlan-parent-input" class="form-label">Parent network card</label>
                    <input type="text" class="form-control" id="macvlan-parent-input" name="macvlan_parent" placeholder="e.g., eth0" list="macvlan-parent-list">
                    <datalist id="macvlan-parent-list"></datalist>
                    <small class="form-text text-muted d-block">The name of the host interface to use for macvlan.</small>
                </div>
            </div>
            <div id="network-ipam-container" style="display: none;">
                <hr>
                <h6>IPAM Configuration</h6>
                <div class="mb-3">
                    <label for="ipam-subnet" class="form-label">Subnet</label>
                    <input type="text" class="form-control" id="ipam-subnet" name="ipam_subnet" placeholder="e.g., 172.25.0.0/16">
                </div>
                <div class="mb-3">
                    <label for="ipam-gateway" class="form-label">Gateway</label>
                    <input type="text" class="form-control" id="ipam-gateway" name="ipam_gateway" placeholder="e.g., 172.25.0.1">
                </div>
                <div class="mb-3">
                    <label for="ipam-ip_range" class="form-label">IP Range (Optional)</label>
                    <input type="text" class="form-control" id="ipam-ip_range" name="ipam_ip_range" placeholder="e.g., 172.25.5.0/24">
                </div>
            </div>
            <div class="mb-3 form-check form-switch" id="network-attachable-container" style="display: none;">
                <input class="form-check-input" type="checkbox" role="switch" id="network-attachable" name="attachable" value="1">
                <label class="form-check-label" for="network-attachable">Attachable</label>
                <small class="form-text text-muted d-block">Allows standalone containers to connect.</small>
            </div>
            <hr>
            <h6>Labels</h6>
            <div id="network-labels-container">
                <!-- Labels will be added here dynamically -->
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="add-network-label-btn">Add Label</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="save-network-btn">Create Network</button>
      </div>
    </div>
  </div>
</div>

<!-- Preview Config Modal -->
<div class="modal fade" id="previewConfigModal" tabindex="-1" aria-labelledby="previewConfigModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewConfigModalLabel">Preview Current Configuration</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="linter-results-container" class="mb-3"></div>
        <pre><code id="preview-yaml-content-container" class="language-yaml">Loading...</code></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" id="deploy-from-preview-btn" class="btn btn-success"><i class="bi bi-rocket-takeoff"></i> Deploy Konfigurasi Ini</button>
      </div>
    </div>
  </div>
</div>

<!-- Sync to Git Modal -->
<div class="modal fade" id="syncGitModal" tabindex="-1" aria-labelledby="syncGitModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="syncGitModalLabel">Pending Git Changes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>The following changes will be committed and pushed to the repository.</p>
        <div id="git-diff-output">
            <!-- Diff2HTML will render here -->
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirm-git-sync-btn"><i class="bi bi-git"></i> Confirm and Sync Changes</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-core.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/diff@5.1.0/dist/diff.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-yaml@4.1.0/dist/js-yaml.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/diff2html@3.4.47/bundles/js/diff2html-ui.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?= base_url('assets/js/main.js') ?>"></script>
</body>
</html> 