#!/bin/sh
# Recarrega o nginx depois de o certbot renovar um certificado.
#
# Sem isto a renovação é silenciosamente inútil: o certbot escreve o certificado
# novo em `/etc/letsencrypt/live/`, e o nginx continua a servir o que carregou no
# arranque até alguém o mandar recarregar. O resultado seria um certificado
# expirado no browser com um válido no disco.
#
# O `certonly` não instala este passo -- é o plugin `--nginx` que o faria. Como a
# configuração do nginx é nossa e versionada, o certificado é emitido com
# `certonly` e a recarga fica aqui.
#
# Instalação:
#   install -Dm755 config/letsencrypt/reload-nginx.sh \
#     /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
#
# Corre para cada certificado renovado, e o directório `deploy/` só é percorrido
# quando houve mesmo renovação -- num `certbot renew` que não renova nada, não.
set -eu

systemctl reload nginx
