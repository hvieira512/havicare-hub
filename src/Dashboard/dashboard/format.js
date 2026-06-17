export const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
}[char]));

export const titleize = value => String(value ?? 'desconhecido')
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, char => char.toUpperCase());

export const ago = value => {
    if (!value) return 'nunca';
    const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(value)) / 1000));
    if (seconds < 60) return `há ${seconds}s`;
    if (seconds < 3600) return `há ${Math.floor(seconds / 60)}m`;
    if (seconds < 86400) return `há ${Math.floor(seconds / 3600)}h`;
    return `há ${Math.floor(seconds / 86400)}d`;
};

export const when = value => {
    if (!value) return '';
    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) return String(value);
    return new Date(parsed).toLocaleString('pt-PT');
};

export const featureLabel = type => ({
    heart_rate: 'Frequência cardíaca',
    blood_pressure: 'Tensão arterial',
    blood_oxygen: 'Oxigénio no sangue',
    blood_sugar: 'Glicemia',
    temperature: 'Temperatura',
    battery: 'Bateria',
    activity: 'Atividade',
    location: 'Localização',
    alarm: 'Alarme',
    heartbeat: 'Sinal de rede',
    sleep: 'Sono',
    ecg: 'ECG',
    hrv: 'VFC',
    weather: 'Meteorologia',
    device_config: 'Configuração',
}[type] || titleize(type));

export const fieldLabel = key => ({
    distanceMeters: 'Distância',
    caloriesKcal: 'Calorias',
    exerciseSeconds: 'Exercício (s)',
    standMinutes: 'Tempo em pé (min)',
    source: 'Origem',
    gpsValid: 'GPS válido',
    speedKmh: 'Velocidade',
    accuracyMeters: 'Precisão',
    code: 'Código',
    lowBattery: 'Bateria fraca',
    fall: 'Queda',
    wearingNotice: 'Aviso de utilização',
    gsmSignal: 'Sinal GSM',
    satelliteCount: 'Satélites',
    steps: 'Passos',
    bodyCelsius: 'Temperatura',
    percent: 'Percentagem',
    chargingState: 'Estado de carga',
    batteryType: 'Tipo de bateria',
    batteryPercent: 'Bateria',
    rollFrequency: 'Frequência de rotação',
    workMode: 'Modo de trabalho',
    glucoseMgDl: 'Glicemia',
    summary: 'Resumo',
    weatherType: 'Tipo de tempo',
    reportedAt: 'Reportado em',
    temperatureCelsius: 'Temperatura',
    lowCelsius: 'Mínima',
    highCelsius: 'Máxima',
    humidityPercent: 'Humidade',
    ack: 'ACK',
    settings: 'Definições',
    intervalSeconds: 'Intervalo (s)',
    intervalMinutes: 'Intervalo (min)',
    password: 'Palavra-passe',
    phone: 'Telefone',
}[key] || titleize(key));

export const commandLabel = command => ({
    'Heart rate': 'Frequência cardíaca',
    'Blood pressure': 'Tensão arterial',
    'Blood oxygen': 'Oxigénio no sangue',
    Temperature: 'Temperatura',
    'Temperature variant': 'Temperatura',
    'Breath rate': 'Frequência respiratória',
    Location: 'Localização',
    'Sleep data': 'Sono',
    ECG: 'ECG',
    HRV: 'VFC',
    PPG: 'PPG',
    'RR interval': 'Intervalo RR',
    Weather: 'Meteorologia',
    'Heart rate and blood pressure': 'Frequência cardíaca e tensão arterial',
}[command.label] || command.label || command.command);

export const displayValue = value => {
    if (Array.isArray(value)) return String(value.length);
    if (value && typeof value === 'object') return JSON.stringify(value);
    return String(value);
};

export const eventTime = payload => {
    const time = Date.parse(payload?.occurredAt || payload?.recordedAt || '');
    return Number.isNaN(time) ? 0 : time;
};

export const rowPayload = row => row?.payload && typeof row.payload === 'object' ? row.payload : row;
