        <div id="deviceBand" class="device-band d-none d-lg-none">
            <div class="container-fluid d-flex align-items-center gap-2 pb-2">
                <button id="deviceBandSelectBtn" class="btn btn-dark device-band-btn flex-shrink-0" type="button" aria-label="Escolher outro dispositivo" title="Escolher outro dispositivo"><?= icon('fa-ellipsis-vertical') ?></button>
                <div class="min-w-0 flex-grow-1">
                    <div id="deviceBandTitle" class="device-band-title fw-semibold text-truncate tabular-nums"></div>
                    <div class="device-band-meta d-flex align-items-center gap-2">
                        <span id="deviceBandDot" class="device-band-dot rounded-circle flex-shrink-0" aria-hidden="true"></span>
                        <span id="deviceBandMeta" class="text-truncate"></span>
                    </div>
                </div>
                <button id="deviceBandEditBtn" class="btn btn-dark device-band-btn flex-shrink-0" type="button" aria-label="Editar dispositivo e configurações" title="Editar dispositivo e configurações"><?= icon('fa-pen') ?></button>
            </div>
        </div>
        <div id="deviceTabs" class="nav device-tabs flex-nowrap d-none d-lg-none" role="tablist">
            <button id="deviceTabTelemetry" class="nav-link active flex-fill" type="button" role="tab" aria-selected="true">Telemetria</button>
            <button id="deviceTabReadings" class="nav-link flex-fill" type="button" role="tab" aria-selected="false" data-bs-target="#telemetryColumn" aria-controls="telemetryColumn">Leituras<span id="deviceTabReadingsCount" class="device-tab-count"></span></button>
            <button id="deviceTabRequests" class="nav-link flex-fill" type="button" role="tab" aria-selected="false" data-bs-target="#downlinkColumn" aria-controls="downlinkColumn">Pedidos<span id="deviceTabRequestsCount" class="device-tab-count"></span></button>
        </div>
