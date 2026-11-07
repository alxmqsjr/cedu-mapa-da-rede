# Cemaden Educação: Mapa da Rede

Plugin WordPress que exibe o **Mapa da Rede** do [Cemaden Educação](https://educacao.cemaden.gov.br/): ações, inscrições, organizações e atividades da rede num mapa interativo e numa lista pesquisável.

Feito com [MapLibre GL JS](https://maplibre.org/) e [Vue 3](https://vuejs.org/), sem depender do plugin JEO e sem chave ou token de serviço de mapas.

- Filtro por categoria, busca por texto e legenda.
- Pontos próximos se abrem em "aranha" ao clicar.
- Layout adaptado a celular (gaveta inferior), tablet e desktop (painel lateral).
- Dados servidos por uma rota REST com cache pronto e comprimido.

> **Atenção:** o plugin lê os tipos de post e campos do site Cemaden Educação (`acao`, `inscricoes`, `organizacao`, `atividade_da_rede`). Num WordPress sem esses tipos, o mapa abre vazio.

## Requisitos

- WordPress 6.6 ou superior
- PHP 8.0 ou superior

## Instalação

### Pelo painel do WordPress (recomendado)

1. Baixe o arquivo `cedu-mapa-da-rede-<versão>.zip` na página [Releases](../../releases) deste repositório.
2. No painel, vá em **Plugins → Adicionar novo → Enviar plugin**, escolha o `.zip` e clique em **Instalar agora**.
3. Clique em **Ativar**.

### Por FTP

1. Descompacte o `.zip` da página Releases.
2. Envie a pasta `cedu-mapa-da-rede` para `wp-content/plugins/`.
3. Ative o plugin em **Plugins**.

> Use o `.zip` da página Releases, e não o botão verde **Code → Download ZIP**: ele cria a pasta com outro nome (`cedu-mapa-da-rede-main`).

## Como usar

Escolha uma das opções:

- **Página inteira:** edite a página, abra **Atributos da página → Modelo** e escolha **Mapa da Rede (plugin)**. O mapa ocupa a área entre o cabeçalho e o rodapé do tema.
- **Dentro de um conteúdo:** insira o shortcode `[cemaden_mapa_rede]`.

Os arquivos do mapa só são carregados nas páginas que o exibem.

## Configuração (opcional)

No `wp-config.php`:

```php
// Mapa base: 'positron' (padrão, OpenFreeMap), 'osm' ou a URL https de um estilo MapLibre.
define( 'CEMADEN_MAPA_REDE_ESTILO', 'positron' );

// Cor da água (mar, lagos e rios). Padrão '#bceeff'; '' mantém a cor original do estilo.
define( 'CEMADEN_MAPA_REDE_COR_AGUA', '#bceeff' );
```

Se o mapa base não responder, o mapa troca sozinho para um mapa de reserva (OpenStreetMap).

## Atualizações

Depois de instalado, o plugin avisa sobre versões novas em **Painel → Atualizações** e na tela de **Plugins**, com o botão **Atualizar agora**, como os plugins do diretório oficial. As versões vêm da página [Releases](../../releases) deste repositório.

Para publicar uma versão:

1. Suba a versão no cabeçalho de `cedu-mapa-da-rede.php` (`Version` e `VERSAO`) e no `readme.txt`.
2. Faça o commit, crie a tag (`git tag -a v1.2.1 -m "1.2.1"`) e envie as duas para o GitHub.
3. Gere o `.zip` (comando em [Desenvolvimento](#desenvolvimento)) e anexe-o a uma nova release dessa tag.

Os sites verificam no máximo a cada 12 horas; **Painel → Atualizações → Verificar novamente** força a consulta. Para desligar a verificação num site: `define( 'CEMADEN_MAPA_REDE_ATUALIZAR', false );` no `wp-config.php`.

## Comandos WP-CLI

```bash
wp cemaden-mapa diagnostico --detalhes   # totais por tipo e itens sem localização
wp cemaden-mapa limpar-cache
wp cemaden-mapa reconstruir
```

## Desenvolvimento

O código-fonte do front-end fica em `src/` (Vue 3 + MapLibre GL JS + Vite) e exige Node 20 ou superior. A pasta `build/` já vem compilada no repositório, então só é preciso compilar ao alterar `src/`.

```bash
npm ci
npm run build
```

Para gerar o `.zip` de uma versão (sem `src/`, `package.json` e demais arquivos de desenvolvimento):

```bash
git archive --format=zip --prefix=cedu-mapa-da-rede/ -o cedu-mapa-da-rede-1.2.2.zip HEAD
```

Detalhes técnicos (rota REST, cache, rota de CEP e changelog) estão no [readme.txt](readme.txt).

## Créditos

- Desenvolvimento: **[Alexandre Marques](https://github.com/alxmqsjr)**.
- [MapLibre GL JS](https://github.com/maplibre/maplibre-gl-js) (BSD-3-Clause) e [Vue](https://github.com/vuejs/core) (MIT): ver `build/LICENSES.txt`.
- Sedes municipais: [kelvins/municipios-brasileiros](https://github.com/kelvins/municipios-brasileiros) (MIT), dados do IBGE: ver `data/LICENSE-municipios.txt`.
- Mapa base: © [OpenFreeMap](https://openfreemap.org/), © [OpenMapTiles](https://openmaptiles.org/), dados © [colaboradores do OpenStreetMap](https://www.openstreetmap.org/copyright).
- Ícones dos marcadores: tema Cemaden Educação.

## Licença

[GPL-2.0-or-later](LICENSE).
