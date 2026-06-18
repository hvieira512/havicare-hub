# hitNCS.py - Monitor Independente de Chamada de Enfermeiros (Versão Corrigida)
import json
import logging
import requests
import paho.mqtt.client as mqtt

# Configurações isoladas do barramento NCS
MQTT_BROKER = "88.99.104.197"
MQTT_PORT = 1883
MQTT_USER = "health-hub"
MQTT_PASS = "hitCare"
MQTT_TOPIC_SUB = "/voerka/#"
TARGET_URL = "http://127.0.0.1:8000/api/telemetry"

logging.basicConfig(
    level=logging.INFO, 
    format="%(asctime)s [hitNCS] - %(message)s"
)

def mapear_alarme_meeyi(key_value):
    """
    Mapeia o código da tecla ('key') recebido do firmware W812 para a severidade da HAVIcare.
    Nota: Ajuste os números das chaves conforme os testes físicos (ex: Verde/Reset vs Chamada).
    """
    # Exemplo baseado no log real onde "8" foi o acionamento do SOS
    if key_value == "8":
        return {"alarm": True, "alarme_tipo": "CHAMADA UTENTE", "evento": "Chamada de Emergência / SOS"}
    
    # Exemplo hipotético para o Botão Verde de Cancelamento (Valide se envia "1", "2" ou "0")
    elif key_value in ["0", "1", "2", "reset"]: 
        return {"alarm": False, "alarme_tipo": "", "evento": "Reset / Chamado Cancelado"}
    
    else:
        return {"alarm": True, "alarme_tipo": "ALERTA GERAL", "evento": f"Ação no Pager (Key {key_value})"}

def on_message(client, userdata, msg):
    try:
        payload_raw = msg.payload.decode("utf-8")
        data = json.loads(payload_raw)
        
        gateway_sn = data.get("from")
        msg_type = data.get("type")
        payload = data.get("payload", {})
        
        ui_payload = {
            "type": "hitncs_event",
            "gateway_sn": gateway_sn,
            "timestamp": data.get("timestamp"),
            "situacao": "Ativo"
        }
        
        # 1. Processamento de Chamadas dos Botões e Puxadores (Type 6 = Evento)
        if "events" in msg.topic and msg_type == 6 and "id" in payload:
            ui_payload["device_id"] = payload.get("id")
            key_value = str(payload.get("key"))
            ui_payload["key_value"] = key_value
            
            # Aplica as regras de criticidade baseadas na TECLA pressionada
            dados_alarme = mapear_alarme_meeyi(key_value)
            ui_payload.update(dados_alarme)
            
            logging.info(f"🚨 [NCS] {ui_payload['evento']} | Gateway: {gateway_sn} | Botão: {ui_payload['device_id']} | Key: {key_value}")
            
            # Despacha para o main.py (hitCARE)
            try:
                requests.post(TARGET_URL, json=ui_payload, timeout=2)
            except requests.exceptions.RequestException:
                logging.error("❌ Falha ao enviar dados para o main.py (Servidor Offline?)")
                
        # 2. Processamento de Supervisão de Linha (Liveness dos Gateways)
        elif "status/online" in msg.topic:
            status_dict = payload.get("status", {})
            if "online" in status_dict:
                is_online = status_dict.get("online", False)
                
                if not is_online:
                    ui_payload.update({
                        "alarm": True,
                        "alarme_tipo": "FALHA DE COMUNICAÇÃO GATEWAY",
                        "evento": "Gateway Desconectado",
                        "situacao": "Offline"
                    })
                    logging.warning(f"⚠️ [NCS] Gateway {gateway_sn} ficou OFFLINE!")
                    try:
                        requests.post(TARGET_URL, json=ui_payload, timeout=2)
                    except requests.exceptions.RequestException: 
                        pass
                else:
                    logging.info(f"🟢 [NCS] Gateway {gateway_sn} está ONLINE.")

    except Exception as e:
        logging.error(f"❌ Erro ao processar mensagem MQTT no hitNCS: {str(e)}")

if __name__ == "__main__":
    print("--------------------------------------------------")
    print("        INICIANDO O SISTEMA hitNCS - V1.10        ")
    print("     (Correção de Parsing Mapeamento MEEYI)       ")
    print("--------------------------------------------------")
    
    client = mqtt.Client()
    client.username_pw_set(MQTT_USER, MQTT_PASS)
    client.on_connect = lambda client, userdata, flags, rc: logging.info(f"📡 Ligado ao broker. Subscrevendo a {MQTT_TOPIC_SUB}") or client.subscribe(MQTT_TOPIC_SUB)
    client.on_message = on_message
    
    try:
        client.connect(MQTT_BROKER, MQTT_PORT, 60)
        client.loop_forever()
    except KeyboardInterrupt:
        logging.info("🔌 hitNCS interrompido manualmente.")
    except Exception as e:
        logging.critical(f"💥 Falha crítica no arranque do hitNCS: {str(e)}")
