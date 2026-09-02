# As bibliotecas de terceiros

Cópias tal e qual do que vinha do CDN, servidas pelo `DashboardHttpServer` em
`/assets/vendor/…` como qualquer outro recurso estático. Não há build step nem
`node_modules` em produção -- o `make update` é um `git pull`, e por isso estes
ficheiros estão no repositório.

| pasta | versão | de onde |
|---|---|---|
| `ag-grid/` | 36.1.0 | `cdn.jsdelivr.net/npm/ag-grid-community@36.1.0/dist/` |
| `bootstrap/` | 5.3.3 | `cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/` |
| `fontawesome/` | 6.5.2 | `cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/` |
| `sweetalert2/` | 11.26.25 | `cdn.jsdelivr.net/npm/sweetalert2@11.26.25/` (`dist/` e `themes/`) |
| `swagger-ui/` | 5.32.14 | `cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.14/` |

Do AG Grid vem só o `ag-grid-community.min.js`, que já traz os estilos: o tema é montado em
código no `dashboard/grid.js`, com o `themeQuartz` e a paleta da casa por cima. Não há folha
de estilos a vendorizar, e por isso também não há uma para manter em par com a versão.

Do Font Awesome vem só o `fa-solid-900.woff2`: a página usa `fa-solid` e mais nada, e o
`@font-face` só descarrega a família que alguém usa. Quem escrever o primeiro `fa-regular`
ou `fa-brands` tem de trazer o `.woff2` respectivo -- o ícone aparece em branco sem ele.

Os ficheiros são byte a byte os do CDN, para se poderem verificar contra a origem. Isso
inclui o `sourceMappingURL` no fim dos minificados: os `.map` não estão aqui, portanto com
as devtools abertas há um 404 por ficheiro. Descarregar 2 MB de mapas para calar isso não
compensa.

## Actualizar

Uma versão nova é descarregar por cima e corrigir a tabela. Os caminhos estão no
`index.php` (AG Grid, Bootstrap, Font Awesome, SweetAlert2) e no
`src/Api/Routes/SystemRoutes.php` (Swagger UI, na página `/api/docs`).

```sh
cd src/Dashboard/assets/vendor
curl -o ag-grid/ag-grid-community.min.js   "https://cdn.jsdelivr.net/npm/ag-grid-community@36.1.0/dist/ag-grid-community.min.js"
curl -o bootstrap/bootstrap.min.css        https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
curl -o bootstrap/bootstrap.bundle.min.js  https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js
curl -o fontawesome/css/all.min.css        https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css
curl -o fontawesome/webfonts/fa-solid-900.woff2 https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/webfonts/fa-solid-900.woff2
curl -o sweetalert2/sweetalert2.all.min.js "https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/dist/sweetalert2.all.min.js"
curl -o sweetalert2/bootstrap-5.min.css    "https://cdn.jsdelivr.net/npm/sweetalert2@11.26.25/themes/bootstrap-5.min.css"
curl -o swagger-ui/swagger-ui.css          "https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.14/swagger-ui.css"
curl -o swagger-ui/swagger-ui-bundle.js    "https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.32.14/swagger-ui-bundle.js"
```
