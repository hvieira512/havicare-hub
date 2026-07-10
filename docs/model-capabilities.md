# Matriz de Capacidades dos Modelos

Este ficheiro é um modelo para registar a cobertura comercial dos modelos.

Use uma linha por modelo no formato `{supplier} {internalModel}`.
Marque cada capacidade com `✅`, `❌` ou `N/A`.

## Telemetria

A grelha de telemetria inclui todas as funcionalidades de telemetria expostas pelo catálogo:

- `battery`
- `activity`
- `heart_rate`
- `blood_pressure`
- `blood_oxygen`
- `temperature`
- `breath_rate`
- `location`
- `sleep`
- `ecg`
- `hrv`
- `ppg`
- `rr_interval`

## Grupos de configuração para venda

Estas são as áreas configuráveis com maior valor do ponto de vista comercial:

- `Contactos`
  - `phonebook`
  - `call_whitelist`
  - `monitor_number`
  - `center_number`
- `SOS`
  - `sos_contacts`
  - `sos_sms_alert`
- `Lembretes`
  - `alarm_clock`
  - `medication_reminders`
- `Saúde / bem-estar`
  - `auto_vitals_interval`
  - `heart_rate_measurement_interval`
  - `blood_pressure_measurement_interval`
  - `blood_oxygen_measurement_interval`
  - `temperature_measurement_interval`
  - `breath_rate_measurement_interval`
  - `ecg_measurement_interval`
  - `hrv_measurement_interval`
  - `ppg_measurement_interval`
  - `rr_interval_measurement_interval`
  - `heart_rate_continuous`
  - `blood_oxygen_continuous`
  - `blood_pressure_trend`
  - `temperature_continuous`
  - `step_goal`
  - `sleep_monitoring`

## Modelo

| Modelo | Bateria | Atividade | Frequência cardíaca | Tensão arterial | Oxigénio no sangue | Temperatura | Frequência respiratória | Localização | Sono | ECG | Variabilidade da frequência cardíaca | PPG | Intervalo RR | Agenda | Lista branca de chamadas | Número de monitorização | Número central | Contactos SOS | Alerta SOS por SMS | Alarme | Lembretes de medicação | Intervalo automático de sinais vitais | Intervalo de frequência cardíaca | Intervalo de tensão arterial | Intervalo de oxigénio no sangue | Intervalo de temperatura | Intervalo de frequência respiratória | Intervalo de ECG | Intervalo de VFC | Intervalo de PPG | Intervalo RR | Frequência cardíaca contínua | Oxigénio no sangue contínuo | Tendência da tensão arterial | Temperatura contínua | Meta de passos | Monitorização do sono |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 4P Touch D41 | ✅ | ✅ |  |  | ❌ |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  | ❌ | ❌ | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |
| 4P Touch Y6S | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  | ❌ | ❌ | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |
| 4P Touch D46 | ✅ | ✅ |  |  | ❌ |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  | ❌ | ❌ | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |
| 4P Touch D44S | ✅ | ✅ |  |  | ❌ |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  | ❌ | ❌ | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |
| Vivistar VL17 | ✅ | ✅ |  |  |  |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ |  |  | ❌ | ❌ |  | ❌ |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Vivistar VL16P | ✅ | ✅ |  |  |  |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ |  |  | ❌ | ❌ |  | ❌ |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Vivistar L08 Pro | ✅ | ✅ |  |  |  |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ |  |  | ❌ | ❌ |  | ❌ |  | ❌ |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Wonlex HW20PRO | ✅ | ✅ |  |  |  |  |  |  |  |  |  |  |  |  | ❌ | ❌ | ❌ |  | ❌ |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |  |

## Notas

- Mantenha isto focado na cobertura comercial de capacidades e não em afinações de protocolo de baixo valor.
- Se um modelo não expuser um grupo de capacidades genéricas, deixe as células em branco ou marque `N/A`.
