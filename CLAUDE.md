# Instruções do projeto

## Produção

Quando o utilizador pedir para verificar, testar, atualizar ou entrar em produção, usar:

- Alias SSH: `hub-prod` (configurado localmente em `~/.ssh/config`)
- Diretório do projeto: `/opt/hitecosystem-devices-hub`
- Serviço: `health-hub`
- Comando habitual de atualização: `make prod-update`

Comandos úteis:

- Entrar no servidor: `ssh hub-prod`
- Consultar o serviço: `service health-hub status`
- Consultar os logs: `journalctl -u health-hub`
- Reiniciar o serviço: `service health-hub restart`

## Fluxo de publicação

Quando o pedido incluir uma atualização de produção:

1. Inspecionar as alterações existentes e preservar trabalho não relacionado.
2. Implementar e testar localmente.
3. Fazer commit e push quando o utilizador o pedir ou quando fizerem parte explícita do fluxo solicitado.
4. Entrar no diretório `/opt/hitecosystem-devices-hub` em produção.
5. Executar `make prod-update`.
6. Verificar o estado e os logs do serviço `health-hub`.
7. Testar a funcionalidade em produção com os dispositivos, API, Redis ou MQTT relevantes.
8. Verificar que não existem regressões relevantes e comunicar os resultados concretos.

## Segurança operacional

- Não guardar passwords, tokens ou chaves neste ficheiro.
- Um pedido para analisar produção autoriza verificações não destrutivas, mas não autoriza automaticamente publicação, reinício do serviço ou alterações de dados.
- Só executar `make prod-update`, reiniciar serviços ou alterar dados quando isso estiver incluído no pedido do utilizador.
- Antes de remover ou alterar dados em produção, resolver e confirmar exatamente os registos afetados.
- Não substituir alterações locais ou remotas que não pertençam à tarefa atual.

