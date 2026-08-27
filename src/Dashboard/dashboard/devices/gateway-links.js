const normalizeKey = (value) => String(value || "").trim().toLowerCase();

export function gatewayKeysFromLinks(links = []) {
    return [...new Set(
        (Array.isArray(links) ? links : [])
            .filter((link) => String(link?.deviceType || "") === "gateway")
            .map((link) => normalizeKey(link.gatewayDeviceKey || link.deviceKey))
            .filter(Boolean),
    )].sort();
}

export function eligibleGateways(devices = [], company = "", licenseId = "0") {
    const expectedCompany = String(company || "");
    const expectedLicense = String(licenseId || "0");

    return (Array.isArray(devices) ? devices : [])
        .filter((device) =>
            String(device?.deviceType || "") === "gateway" &&
            String(device?.company || "") === expectedCompany &&
            String(device?.licenseId || "0") === expectedLicense,
        )
        .sort((a, b) => normalizeKey(a?.imei).localeCompare(normalizeKey(b?.imei)));
}

export function gatewayLinkChanges(currentKeys = [], selectedKeys = []) {
    const current = new Set(currentKeys.map(normalizeKey).filter(Boolean));
    const selected = new Set(selectedKeys.map(normalizeKey).filter(Boolean));

    return {
        add: [...selected].filter((key) => !current.has(key)).sort(),
        remove: [...current].filter((key) => !selected.has(key)).sort(),
    };
}
