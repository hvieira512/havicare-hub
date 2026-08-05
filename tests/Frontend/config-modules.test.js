import test from "node:test";
import assert from "node:assert/strict";

import {
    takePillsInput,
    takePillsReminderGroup,
} from "../../src/Dashboard/dashboard/config/four-p-touch-take-pills.js";
import {
    defaultWonlexWeather,
    readWonlexWeather,
    wonlexWeatherInput,
} from "../../src/Dashboard/dashboard/config/wonlex-weather.js";

test("4P Touch medication UI escapes values and respects the reminder limit", () => {
    const html = takePillsInput({
        reminderText: '<script>alert("x")</script>',
        reminderSettings: [
            {time: "08:00", enabled: true, frequency: 1},
            {time: "20:00", enabled: false, frequency: 2},
        ],
    }, {limit: 1});

    assert.match(html, /&lt;script&gt;alert\(&quot;x&quot;\)&lt;\/script&gt;/);
    assert.equal((html.match(/data-takepills-reminder-group=/g) || []).length, 1);
    assert.match(html, /data-action="addTakePillsReminder" disabled/);
});

test("4P Touch custom-frequency reminder renders the custom field", () => {
    const html = takePillsReminderGroup(
        {time: "09:30", enabled: true, frequency: 3, custom: "0111110"},
        0,
        [{value: 3, label: "Personalizado"}],
    );

    assert.match(html, /data-takepills-custom-wrapper="0"/);
    assert.doesNotMatch(html, /data-takepills-custom-wrapper="0"[^>]*d-none/);
    assert.match(html, /value="0111110"/);
});

test("Wonlex weather defaults and serializes form data to wire format", () => {
    assert.deepEqual(defaultWonlexWeather(), {
        iIsCDMA: "0", weather: "", weatherType: 0, province: "", city: "",
        adcode: "", temperature: "", winddirection: "", windpower: "",
        humidity: "", daytemp: "", nighttemp: "", reporttime: "",
    });

    const values = {
        weather: "Nublado", weatherType: "1", province: "Lisboa", city: "Lisboa",
        adcode: "1106", temperature: "20", winddirection: "N", windpower: "3",
        humidity: "70", daytemp: "22", nighttemp: "14", reporttime: "2026-08-05T10:15",
    };
    const section = {
        querySelector(selector) {
            const field = selector.match(/data-weather-field="([^"]+)"/)?.[1];
            if (field === "iIsCDMA") return {checked: true};
            return field in values ? {value: values[field]} : null;
        },
    };

    const payload = readWonlexWeather(section);
    assert.equal(payload.reporttime, "2026-08-05 10:15:00");
    assert.equal(payload.weatherType, 1);
    assert.equal(payload.iIsCDMA, "1");
    assert.match(wonlexWeatherInput(payload), /value="Lisboa"/);
});
