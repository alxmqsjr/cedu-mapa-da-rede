=== Cemaden Educação: Mapa da Rede ===
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.1.3
License: GPL-2.0-or-later

Mapa da Rede do Cemaden Educação com MapLibre GL JS, sem dependência do JEO.

== Descrição ==

Exibe num mapa e numa lista pesquisável:

* Ações: CPTs `acao` e `inscricoes` (exibidos juntos como "Ações");
* Organizações: CPT `organizacao`;
* Atividades da rede: CPT `atividade_da_rede`.

Localização, em ordem de preferência:

* organização: meta `geolocalizacao` (GeoJSON) ou sede do município (`cidade` + `estado`);
* ação: `geolocalizacao` própria, local da organização (se `reuse_address` não for "no") ou sede do município;
* inscrição: sede do município (`municipio` + `estado`);
* atividade da rede: local da organização (`institution` ou `organizacao`).

Pontos posicionados pela sede do município aparecem com um anel ("localização aproximada").
Itens sem local entram só na lista e na busca.

== Uso ==

* Modelo de página "Mapa da Rede (plugin)" (Páginas → Atributos da página → Modelo), ou
* shortcode `[cemaden_mapa_rede]` em qualquer conteúdo.

Os arquivos do mapa só são carregados nas páginas que o exibem.

== Configuração (opcional, wp-config.php) ==

`define( 'CEMADEN_MAPA_REDE_ESTILO', 'positron' );`

* `positron` (padrão): OpenFreeMap Positron, livre e sem chave;
* `osm`: tiles padrão do OpenStreetMap (sujeito à política de uso do OSM);
* URL https de um estilo MapLibre (JSON).

Se o mapa base não carregar (serviço fora do ar ou sem resposta em 15 s), o front-end troca sozinho para um mapa de reserva (tiles do OpenStreetMap + fontes do projeto MapLibre).

`define( 'CEMADEN_MAPA_REDE_COR_AGUA', '#bceeff' );`

* cor da água (mar, lagos e rios) no mapa base vetorial; padrão `#bceeff`;
* `''` mantém a cor original do estilo. Não se aplica ao mapa de reserva (imagens do OSM).

Nenhuma chave ou token é necessário.

Filtro: `cemaden_mapa_rede_mapa_base` (configuração do mapa base).

== REST ==

`GET /wp-json/cemaden-mapa-rede/v1/itens`: FeatureCollection GeoJSON (público, somente conteúdo publicado e campos já públicos).

* Resposta pronta e comprimida guardada na option `cemaden_mapa_rede_cache` (sem autoload; ~300 KB).
* Alterar um post/meta usado marca o cache como desatualizado; o WP-Cron reconstrói em ~15 s. Enquanto isso, serve a versão anterior; sem cron, a primeira requisição após 60 s reconstrói.
* Trava atômica: só uma reconstrução por vez. Se não houver cache e outra reconstrução estiver em andamento, responde 503 + Retry-After (o front-end tenta de novo).
* ETag/304, gzip feito pelo plugin quando o cliente aceita, Cache-Control de 5 minutos.

Quando o JEO não está carregado, o plugin também responde `GET /wp-json/cemaden/v1/endereco/{cep}` (rota do cadastro do tema), no mesmo formato, usando ViaCEP + Nominatim e, como reserva, a sede do município. Proteções: CEP validado; cache por CEP (30 dias) e por endereço (30 dias); no máximo 30 consultas externas por IP a cada 10 minutos (429 acima disso); no máximo 1 chamada por segundo ao Nominatim, sem bloquear o PHP.

== WP-CLI ==

* `wp cemaden-mapa diagnostico [--detalhes]`: totais por tipo e itens sem localização.
* `wp cemaden-mapa limpar-cache`
* `wp cemaden-mapa reconstruir`

== Desenvolvimento ==

Fontes em `src/` (Vue 3 + MapLibre GL JS + Vite). Requer Node 20+.

    npm ci
    npm run build

Gera `build/mapa-rede.js`, `build/mapa-rede.css`, `build/maplibre-worker.js` e `build/LICENSES.txt`.
`src/`, `node_modules/`, `package*.json` e `vite.config.js` não vão para produção.

Qualidade (PHPCS com WordPress Coding Standards 3, instalado via Composer fora do plugin):

    phpcs --standard=WordPress --extensions=php --ignore=node_modules/*,data/*,build/* .

`data/municipios.php` é gerado por `_dev/mapa-rede/tools/gerar-municipios.php`.

== Créditos ==

* Desenvolvimento: Alexandre Marques.

* MapLibre GL JS (BSD-3-Clause) e Vue (MIT): ver build/LICENSES.txt.
* Sedes municipais: kelvins/municipios-brasileiros (MIT), dados do IBGE: ver data/LICENSE-municipios.txt.
* Mapa base: © OpenFreeMap, © OpenMapTiles, dados © colaboradores do OpenStreetMap.
* Ícones dos marcadores: tema Cemaden Educação.

== Changelog ==

= 1.1.3 =
* Pasta e arquivo principal renomeados para `cedu-mapa-da-rede` (text domain `cedu-mapa-da-rede`). O nome exibido, a rota REST, as options e o identificador do modelo de página não mudam.
* Legenda com mais espaço interno vertical.

= 1.1.2 =
* Telas: mapa logo abaixo do cabeçalho do tema (altura medida, sem faixa vazia); celular deitado com a gaveta visível e rolável; créditos do mapa visíveis acima da gaveta e recolhidos no "i" em telas estreitas; legenda recolhida em telas pequenas/baixas e aberta pelo título inteiro; ponto localizado acima da legenda e popup sobre ela; cards compactos (miniatura à esquerda) no painel lateral de 992 a 1440px; botão de filtro sem quebra de linha; gaveta sem vazamento lateral (100vw).
* Rótulos do mapa base em português ("Brasil", "Bolívia").

= 1.1.1 =
* Visual: água do mapa base em azul claro (`#bceeff`), configurável por `CEMADEN_MAPA_REDE_COR_AGUA` ou pelo filtro `cemaden_mapa_rede_mapa_base`.

= 1.1.0 =
* Segurança e desempenho: cache pronto e comprimido com reconstrução em segundo plano e trava; ETag/304 e gzip; leitura só das metas usadas (memória de +57 MB para +17 MB por reconstrução); rota do CEP com cache, limite por IP e sem espera bloqueante; validação dos parâmetros da URL; mapa base de reserva; aviso quando os arquivos do build estão ausentes.
* Visual: sem bordas de seleção; altura do painel medida (barra de administração).

= 1.0.0 =
* Primeira versão: substitui o mapa do tema baseado no JEO.
