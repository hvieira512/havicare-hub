let state;
let onRenderSelection = () => {};
let eventSource = null;
let currentImei = '';

export function initDeviceStream(context) {
    state = context.state;
    onRenderSelection = context.renderSelection;
    window.addEventListener('hub-dashboard-api-token-updated', handleTokenUpdated);
}

export function connectDeviceStream(imei) {
    currentImei = imei;
    closeDeviceStream();
    if (!currentImei) {
        return;
    }

    const url = new URL(
        `/api/devices/${encodeURIComponent(imei)}/stream`,
        window.location.origin,
    );
    const token = window.hubDashboardApiToken?.access_token || "";
    if (token) {
        url.searchParams.set("access_token", token);
    }

    eventSource = new EventSource(url);
    eventSource.addEventListener("snapshot", handleStreamUpdate);
    eventSource.addEventListener("update", handleStreamUpdate);
    eventSource.onerror = function () {
        if (eventSource?.readyState === EventSource.CLOSED) {
            closeDeviceStream();
        }
    };
}

export function disconnectDeviceStream() {
    currentImei = '';
    closeDeviceStream();
}

function closeDeviceStream() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
}

function handleTokenUpdated() {
    if (!currentImei) {
        return;
    }
    if (
        document.body.dataset.dashboardAuthRequired === "true"
        && !window.hubDashboardApiToken?.access_token
    ) {
        closeDeviceStream();
        return;
    }

    connectDeviceStream(currentImei);
}

function handleStreamUpdate(event) {
    const data = JSON.parse(event.data);
    if (!state.selectedDetail) return;
    state.selectedDetail.recent = {
        telemetry: data.telemetry || [],
        events: data.events || [],
        commands: data.commands || [],
    };
    onRenderSelection();
}
