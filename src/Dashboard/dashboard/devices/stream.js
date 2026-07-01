let state;
let onRenderSelection = () => {};
let eventSource = null;

export function initDeviceStream(context) {
    state = context.state;
    onRenderSelection = context.renderSelection;
}

export function connectDeviceStream(imei) {
    disconnectDeviceStream();
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
            disconnectDeviceStream();
        }
    };
}

export function disconnectDeviceStream() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
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
