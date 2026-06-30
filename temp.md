# Reunião 24/06/2026

- Investigar funcionalidades de câmara dos 4P
- Não perder tempo com Wonlex, para já
- Chave primária composta chave do dispositivo + empresa

# Backlog

- Chave Primária Composta Empresa + IMEI
- Verificar se os filtros estão a funcionar
- Verificar documentação Wonlex ver se falta algo para o relógio estar:
    - Desligar tão rápido do servidor
    - Não enviar dados de volta de pedidos
    - Ver que dados ele mede automaticamente sem pedir

# Doing

- Adicionar logging à API
- Verificar integração do PUT `/api/devices/{imei}` se envia em bruto downlinks para o dispositivo
    - Funciona mas o Chavarria dá erro
- `GET /api/devices/{imei}` deverá refletir a última configuração enviada, independentemente se o relógio já devolveu de volta que a configuração foi aplicada. Temos de remover o conceito de timeout de um pedido de configuração ou apenas exagerar bastante no timeout?

# Done

- Adicionar tópico company antes da licença
- Endpoint API associar dispositivo pela chave usada para empresa + licença
- Endpoint API deassociar dispositivo a licença e empresa
- Radares passarem pelo Hub, criar generalização de MQTT
    - Ouvir tópico
    - Tratar dados
    - Redirecionar dados para tópico dentro do Hub
- API
    - Generalizar configurações entre os relógios
    - Registar o que cada modelo pode ou não enviar a nível de telemetria
    - Pedido genérico de configuração do relógio
- Integração hitCare
    - Alterar integração para ouvir dentro do hitCare no tópico
    - API
    - Esconder device ID quando se trata de um relógio, apenas inserir automaticamente quando se trata de um 4PTouch
    - Dispositivos são sempre adicionados sem empresa e sem licença
