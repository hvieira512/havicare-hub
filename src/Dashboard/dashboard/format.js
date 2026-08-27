export const esc = (value) =>
    String(value ?? "").replace(
        /[&<>"']/g,
        (char) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;",
            })[char],
    );

export const titleize = (value) =>
    String(value ?? "desconhecido")
        .replace(/[_-]+/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase());

export const ago = (value) => {
    if (!value) return "nunca";
    const seconds = Math.max(
        0,
        Math.floor((Date.now() - Date.parse(value)) / 1000),
    );
    if (seconds < 60) return `há ${seconds}s`;
    if (seconds < 3600) return `há ${Math.floor(seconds / 60)}m`;
    if (seconds < 86400) return `há ${Math.floor(seconds / 3600)}h`;
    return `há ${Math.floor(seconds / 86400)}d`;
};

export const when = (value) => {
    if (!value) return "";
    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) return String(value);
    return new Date(parsed).toLocaleString("pt-PT");
};

/**
 * A hora de uma linha de lista: dia, mês e hora, sem ano.
 *
 * A coluna da hora e a ultima de quatro em meio painel, e "24/08/2026, 14:34:44" ocupava
 * la um terco da largura para dizer o ano quatro vezes por linha. A janela de filtro
 * comeca por omissao a sete dias, por isso o dia e o mes bastam para nao haver duvida.
 */
export const whenShort = (value) => {
    if (!value) return "";
    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) return String(value);
    const date = new Date(parsed);
    return `${String(date.getDate()).padStart(2, "0")}/${String(date.getMonth() + 1).padStart(2, "0")}, ${date.toLocaleTimeString("pt-PT")}`;
};

export const fieldLabel = (key) =>
    ({
        distanceMeters: "Distância",
        caloriesKcal: "Calorias",
        exerciseSeconds: "Exercício (s)",
        standMinutes: "Tempo em pé (min)",
        source: "Origem",
        gpsValid: "GPS válido",
        speedKmh: "Velocidade",
        accuracyMeters: "Precisão",
        code: "Código",
        pagerId: "Pager",
        buttonId: "Botão",
        gatewayId: "Gateway",
        lowBattery: "Bateria fraca",
        fall: "Queda",
        wearingNotice: "Aviso de utilização",
        gsmSignal: "Sinal GSM",
        pressType: "Tipo de toque",
        triggerCount: "Total de toques",
        presses: "Toques",
        xMg: "Eixo X",
        yMg: "Eixo Y",
        zMg: "Eixo Z",
        magnitudeMg: "Intensidade",
        interface: "Interface",
        networkType: "Rede",
        signalQuality: "Qualidade do sinal",
        signalStrengthDbm: "Intensidade do sinal",
        satelliteCount: "Satélites",
        steps: "Passos",
        bodyCelsius: "Temperatura",
        percent: "Percentagem",
        affectedChannelCount: "Canais afetados",
        maximumDelta: "Delta máximo",
        chargingState: "Estado de carga",
        batteryType: "Tipo de bateria",
        batteryPercent: "Bateria",
        rollFrequency: "Frequência de rotação",
        workMode: "Modo de trabalho",
        glucoseMgDl: "Glicemia",
        summary: "Resumo",
        reportedAt: "Reportado em",
        temperatureCelsius: "Temperatura",
        lowCelsius: "Mínima",
        highCelsius: "Máxima",
        humidityPercent: "Humidade",
        people: "Pessoas",
        breathing: "Respiração",
        sleep_state: "Estado do sono",
        walking_distance: "Distância a andar",
        walking_time: "Tempo a andar",
        meditation_time: "Tempo em meditação",
        in_bed_time: "Tempo na cama",
        standing_time: "Tempo em pé",
        multiplayer_time: "Tempo em atividade",
        breathing_active: "Respiração ativa",
        avg_breathing_per_minute: "Respiração média/min",
        avg_heart_rate_per_minute: "FC média/min",
        breathing_status_per_minute: "Estado respiratório/min",
        heart_rate_status_per_minute: "Estado da FC/min",
        vital_signs_status: "Estado dos sinais vitais",
        ack: "ACK",
        settings: "Definições",
        intervalSeconds: "Intervalo (s)",
        intervalMinutes: "Intervalo (min)",
        interval: "Intervalo (min)",
        password: "Palavra-passe",
        phone: "Telefone",
        Battery: "Bateria (%)",
        bindStatus: "Vinculação",
        switchState: "Estado",
        sleepStartTime: "Início do sono",
        sleepEndTime: "Fim do sono",
        sleepTarget: "Meta de sono (min)",
        reminderValue: "Valor de alerta",
        RemindValue: "Valor de alerta",
        remindValue: "Limite principal",
        hpWarn: "Sistólica máxima",
        LPWarn: "Diastólica máxima",
        exerciseSwitchState: "Alarmes em exercício",
        exerciseHRMin: "FC mínima em exercício",
        exerciseHRMax: "FC máxima em exercício",
        exerciseRemindValue: "Limite em exercício",
        event: "Evento",
        help_call: "Chamada de ajuda",
        reset: "Tecla acionada",
        deviceId: "ID do dispositivo",
        topicSourceId: "Origem",
        messageType: "Tipo",
        from: "Gateway",
        transparent: "Transparente",
        key: "Tecla",
        lastEvent: "Último evento",
        personIndex: "Pessoa",
    })[key] || titleize(key);

/**
 * O valor de um campo cujo conteudo e uma enumeracao, em portugues.
 *
 * O `fieldLabel` acima traduz o nome do campo; isto traduz o que la esta dentro. Faltava, e
 * via-se: o cartao dizia "Estado do sono" ao lado de "Awake", porque o descodificador do
 * radar devolvia as etiquetas do documento do fabricante e ninguem as convertia. O hub
 * passou a enviar enumeracoes -- `awake`, `lying_down` -- e a traducao e deste lado.
 *
 * Por chave de campo e nao um dicionario global: `low` e `high` querem dizer coisas
 * diferentes conforme se fale de uma frequencia cardiaca ou de um nivel de bateria.
 */
const FIELD_VALUE_LABELS = {
    sleep_state: {
        awake: "Acordado",
        light_sleep: "Sono leve",
        deep_sleep: "Sono profundo",
        undefined: "Indefinido",
    },
    posture: {
        initialization: "A iniciar",
        walking: "A andar",
        suspected_fall: "Suspeita de queda",
        squatting: "Agachado",
        standing: "De pé",
        fall_confirmation: "Queda confirmada",
        lying_down: "Deitado",
        suspected_sitting_on_ground: "Suspeita de estar sentado no chão",
        confirmed_sitting_on_ground: "Sentado no chão",
        sitting_up_bed: "Sentado na cama",
        suspected_sitting_up_bed: "Suspeita de estar sentado na cama",
        confirmed_sitting_up_bed: "Sentado na cama",
        unknown: "Desconhecida",
    },
    lastEvent: {
        no_event: "Sem eventos",
        enter_room: "Entrou na divisão",
        leave_room: "Saiu da divisão",
        enter_area: "Entrou na área",
        leave_area: "Saiu da área",
        unknown: "Desconhecido",
    },
};

export const fieldValue = (key, value) => {
    if (value === undefined || value === null || value === "") return "-";

    // Um booleano chegava aqui como texto e saia titleizado do inglês: o cartão de alarme
    // dizia "Queda: False" e "Bateria fraca: False", e o de localização "GPS válido: False".
    if (typeof value === "boolean") return value ? "Sim" : "Não";

    const raw = String(value);
    const translated = FIELD_VALUE_LABELS[key]?.[raw];
    if (translated) return translated;

    // So o que parece uma enumeracao e que se embeleza. Um numero, uma percentagem ou um
    // texto livre passam intactos: o `titleize` transformava `78` em `78` mas
    // `2026-08-26T19:00:00Z` em `2026-08-26T19:00:00Z` com as maiusculas trocadas, e nao e
    // trabalho dele arriscar isso.
    return /^[a-z][a-z0-9]*(_[a-z0-9]+)*$/.test(raw) ? titleize(raw) : raw;
};

export const commandLabel = (command) =>
    ({
        "Heart rate": "Frequência cardíaca",
        "Blood pressure": "Tensão arterial",
        "Systolic blood pressure": "Tensão arterial sistólica",
        "Diastolic blood pressure": "Tensão arterial diastólica",
        "Blood oxygen": "Oxigénio no sangue",
        Temperature: "Temperatura",
        "Temperature variant": "Temperatura",
        "Breath rate": "Frequência respiratória",
        Location: "Localização",
        "Sleep data": "Sono",
        ECG: "ECG",
        HRV: "VFC",
        PPG: "PPG",
        "RR interval": "Intervalo RR",
        "Heart rate and blood pressure":
            "Frequência cardíaca e tensão arterial",
    })[command.label] ||
    command.label ||
    command.command;

export const eventTime = (payload) => {
    const time = Date.parse(payload?.occurredAt || payload?.recordedAt || "");
    return Number.isNaN(time) ? 0 : time;
};

export const rowPayload = (row) =>
    row?.payload && typeof row.payload === "object" ? row.payload : row;
