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
- `Deteção e mensagens`
  - `fall_detection`
  - `push_message`
- `Lembretes`
  - `alarm_clock`
  - `medication_reminders`
- `Saúde / bem-estar`
  - `auto_vitals_interval`
  - `step_goal`
  - `sleep_monitoring`

## Modelo

| Modelo | Bateria | Atividade | Frequência cardíaca | Tensão arterial | Oxigénio no sangue | Temperatura | Localização | Sono | ECG | Variabilidade da frequência cardíaca | PPG | Intervalo RR | Frequência respiratória | Deteção de queda | Mensagem personalizada | Contactos SOS | Alerta SOS por SMS | Lista telefónica | Lista branca de chamadas | Número de monitorização | Número central | Despertador | Lembretes de medicação | Intervalo automático de sinais vitais | Meta de passos | Monitorização do sono |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 4P Touch D41 | ✅ | ✅ |  |  | ❌ |  |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  |  |  | ❌ |  |
| 4P Touch Y6S | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  |  |  | ❌ |  |
| 4P Touch D46 | ✅ | ✅ |  |  | ❌ |  |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  |  |  | ❌ |  |
| 4P Touch D44S | ✅ | ✅ |  |  | ❌ |  |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |  |  |  |  |  |  | ❌ |  |
| Vivistar VL17 | ✅ | ✅ |  |  |  |  |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  | ❌ |  |  | ❌ | ❌ |  |  |  | ❌ | ❌ |
| Vivistar VL16P | ✅ | ✅ |  |  |  |  |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  | ❌ |  |  | ❌ | ❌ |  |  |  | ❌ | ❌ |
| Vivistar L08 Pro | ✅ | ✅ |  |  |  |  |  | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |  |  |  | ❌ |  |  | ❌ | ❌ |  |  |  | ❌ | ❌ |
| Wonlex HW20PRO | ✅ | ✅ |  |  |  |  |  |  |  |  |  |  |  |  | ❌ |  |  | ❌ | ❌ | ❌ | ❌ |  |  |  |  |  |

## Notas

- Mantenha isto focado em capacidades com valor comercial.
- Use `❌` apenas quando o fornecedor não suporta essa capacidade no código.
- Deixe em branco quando a capacidade ainda não estiver confirmada para o modelo.
