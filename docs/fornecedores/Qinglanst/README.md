# Qinglanst

`Instruction of Cloud2cloud integration V2.2.3_Jan 16.pdf` é a documentação
oficial do fabricante (plataforma "Hobacare"): eventos HTTP, formato das
mensagens MQTT (trajetória, respiração/ritmo cardíaco, sono) e a REST API em
`qinglanst.com/prod-api/thirdparty/v2`. O que o hub já sabia do protocolo foi
lido das tramas e está no [capítulo 04](../../04-ingestao-mqtt-radar.md).

Os limiares dos alarmes de sinais vitais estão na secção 5.3.5
(`setBreathHeartParam`). Os valores por omissão do radar são: respiração 8–24/min
e ritmo cardíaco 50–90 bpm, com alarme após 1 minuto fora do intervalo. O hub e
o hitCare ainda usam números diferentes para a mesma leitura.
