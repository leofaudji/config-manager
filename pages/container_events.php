<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$conn = Database::getInstance()->getConnection();
$hosts_result = $conn->query("SELECT id, name FROM docker_hosts ORDER BY name ASC");
$hosts = $hosts_result->fetch_all(MYSQLI_ASSOC);
$conn->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-activity"></i> Container Events</h1>
</div>

<div class="row">
    <div class="col-lg-4 col-md-6 mb-3">
        <div class="input-group">
            <label class="input-group-text" for="host-select"><i class="bi bi-hdd-network-fill"></i></label>
            <select id="host-select" class="form-select">
                <option value="">-- Select a Host to Monitor --</option>
                <?php foreach ($hosts as $host): ?>
                    <option value="<?= htmlspecialchars($host['id']) ?>"><?= htmlspecialchars($host['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-lg-8 col-md-6 mb-3 d-flex align-items-center">
        <span id="connection-status" class="badge bg-secondary">Disconnected</span>
        <div class="ms-auto d-flex align-items-center">
            <div class="input-group input-group-sm me-2">
                <label class="input-group-text" for="event-type-filter"><i class="bi bi-funnel"></i></label>
                <select id="event-type-filter" class="form-select">
                    <option value="">All Event Types</option>
                    <option value="start">start</option>
                    <option value="stop">stop</option>
                    <option value="die">die</option>
                    <option value="oom">oom</option>
                    <option value="kill">kill</option>
                    <option value="destroy">destroy</option>
                    <option value="create">create</option>
                </select>
            </div>
            <div class="input-group input-group-sm">
                <label class="input-group-text" for="container-name-filter"><i class="bi bi-search"></i></label>
                <input type="text" id="container-name-filter" class="form-control" placeholder="Filter by container name...">
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">Live Event Stream</h5>
    </div>
    <div class="card-body">
        <pre id="events-container" class="bg-dark text-light p-3 rounded" style="min-height: 60vh; max-height: 70vh; overflow-y: auto; white-space: pre-wrap; word-break: break-all;"></pre>
    </div>
</div>

<script>
window.pageInit = function() {
    const hostSelect = document.getElementById('host-select');
    const eventsContainer = document.getElementById('events-container');
    const connectionStatus = document.getElementById('connection-status');
    let eventSource = null;
    const eventTypeFilter = document.getElementById('event-type-filter');
    const containerNameFilter = document.getElementById('container-name-filter');

    function getStatusClass(status) {
        if (['start', 'unpause'].includes(status)) return 'text-success';
        if (['stop', 'kill', 'die', 'pause', 'destroy'].includes(status)) return 'text-danger';
        if (['oom'].includes(status)) return 'text-warning'; // Out of Memory
        return 'text-info'; // create, attach, etc.
    }

    function getIconForStatus(status) {
        const iconMap = {
            start: 'bi-play-circle-fill',
            stop: 'bi-stop-circle-fill',
            die: 'bi-heartbreak-fill',
            kill: 'bi-radioactive',
            create: 'bi-plus-circle-fill',
            destroy: 'bi-trash-fill',
            pause: 'bi-pause-circle-fill',
            unpause: 'bi-play-circle-fill',
            oom: 'bi-exclamation-triangle-fill'
        };
        return iconMap[status] || 'bi-info-circle-fill';
    }

    function addEventToView(event) {
        const timestamp = new Date(event.time * 1000).toLocaleString();
        const status = event.status;
        const actor = event.Actor;
        const name = actor.Attributes.name || 'N/A';
        const image = actor.Attributes.image || 'N/A';

        // Apply filters
        const typeFilterValue = eventTypeFilter.value;
        const nameFilterValue = containerNameFilter.value.toLowerCase();

        if (typeFilterValue && status !== typeFilterValue) {
            return; // Skip if type doesn't match
        }
        if (nameFilterValue && !name.toLowerCase().includes(nameFilterValue)) {
            return; // Skip if name doesn't match
        }

        const statusClass = getStatusClass(status);
        const iconClass = getIconForStatus(status);

        const eventLine = document.createElement('div');
        eventLine.innerHTML = `[${timestamp}] <i class="bi ${iconClass} ${statusClass}"></i> <strong class="${statusClass}">${status.toUpperCase()}</strong> - Name: <strong>${name}</strong>, Image: <em>${image}</em>`;
        
        // Prepend to show newest first
        eventsContainer.insertBefore(eventLine, eventsContainer.firstChild);

        // Limit the number of lines to prevent browser slowdown
        if (eventsContainer.children.length > 500) {
            eventsContainer.removeChild(eventsContainer.lastChild);
        }
    }

    hostSelect.addEventListener('change', function() {
        const hostId = this.value;

        // Close any existing connection
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }

        eventsContainer.innerHTML = ''; // Clear the view

        if (!hostId) {
            connectionStatus.textContent = 'Disconnected';
            connectionStatus.className = 'badge bg-secondary';
            eventsContainer.textContent = 'Please select a host to start monitoring events.';
            return;
        }

        connectionStatus.textContent = 'Connecting...';
        connectionStatus.className = 'badge bg-warning';
        eventsContainer.textContent = 'Attempting to connect to event stream...';

        eventSource = new EventSource(`<?= base_url('/api/hosts/') ?>${hostId}/events`);

        eventSource.onopen = function() {
            connectionStatus.textContent = 'Connected';
            connectionStatus.className = 'badge bg-success';
            eventsContainer.textContent = 'Connection established. Waiting for events...\n\n';
        };

        eventSource.onmessage = function(e) {
            const eventData = JSON.parse(e.data);
            if (eventData.error) {
                console.error('SSE Error:', eventData.error);
                eventsContainer.textContent += `\nERROR: ${eventData.error}\n`;
                eventSource.close();
                connectionStatus.textContent = 'Error';
                connectionStatus.className = 'badge bg-danger';
            } else {
                addEventToView(eventData);
            }
        };

        eventSource.onerror = function() {
            connectionStatus.textContent = 'Connection Failed';
            connectionStatus.className = 'badge bg-danger';
            eventsContainer.textContent += '\nConnection to the event stream failed. The host might be unreachable or an error occurred.';
            eventSource.close();
        };
    });

    // Add event listeners for filters
    eventTypeFilter.addEventListener('change', () => {
        // No need to reload, just wait for new events
        showToast('Event type filter applied. New events will be filtered.', true);
    });
    containerNameFilter.addEventListener('input', debounce(() => {
        showToast('Container name filter applied. New events will be filtered.', true);
    }, 400));
};
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>