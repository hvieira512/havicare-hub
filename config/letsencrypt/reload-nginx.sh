#!/bin/sh
# Recarrega o nginx depois de o certbot renovar um certificado, que senão continua a servir o
# que carregou no arranque. Com `certonly`, o certbot não instala este passo.
#
# Instalação:
#   install -Dm755 config/letsencrypt/reload-nginx.sh \
#     /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
#
# Só corre quando houve mesmo renovação.
set -eu

systemctl reload nginx
