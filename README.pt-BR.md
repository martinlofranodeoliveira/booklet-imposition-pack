# Booklet Imposition Pack

Versão de desenvolvimento **0.1.0**, da [Lofrano Tecnologia](https://lofranotecnologia.com.br). Licença MIT. [English](README.md).

Biblioteca PHP Composer e CLI local para organizar páginas PDF estáticas em cadernos de 4, 8 ou 16 páginas. Cada diretório concluído contém PDF, mapa explícito de páginas e brancos, relatório de validação independente e PNG do primeiro lado. Sem servidor, navegador, telemetria ou serviço online em execução.

## Instalação, testes e demonstração

Ambiente qualificado: Linux, PHP 8.4, GD, Python 3 com biblioteca padrão, qpdf 11.3 com JSON v2 e Poppler 22.12. Dependências PHP exatas: FPDI 2.6.9 e FPDF 1.9.0. O pequeno supervisor Python mantém a posse dos processos; a aplicação continua em PHP.

```sh
docker build --tag booklet-imposition-pack .
docker run --rm --network none booklet-imposition-pack php tests/run.php
docker run --rm --network none booklet-imposition-pack php bin/booklet.php demo --assert --json
```

Com essas ferramentas já instaladas:

```sh
composer install --no-plugins --no-scripts
php tests/run.php
php bin/booklet.php demo --assert
php bin/booklet.php demo --assert --json --output /tmp/booklet-qa
```

`--output` exige um diretório novo, com diretório pai existente. A demonstração conserva a fonte sintética, o PDF final e `first-side.png` para revisão visual local, somente leitura. Verifica estrutura, texto extraído e amostras rasterizadas; **não** realiza inspeção visual humana. Examine o PNG final, não apenas o texto extraído.

Campos determinísticos da saída humana, além do caminho variável e da observação sobre inspeção visual:

```text
Booklet Imposition Pack development 0.1.0
Verified: yes
Sheets: 2; sides: 4
Pairs: [8,1] [2,7] [6,3] [4,5]
First side: 600 x 400 pixels; markers P8 P1
Samples: 160,160,160 / 20,20,20 / 255,255,255
```

`--json` emite um único resultado JSON com pares e amostras efetivamente medidos. Falhas retornam código de saída 1 e código estável em stderr. Testes usam ferramentas reais e auxiliares fixos de falha, sem rede.

## Uso da biblioteca

```php
require 'vendor/autoload.php';

$pack = new Lofrano\Booklet\Imposer();
$resultado = $pack->impose(
    '/tmp/fonte.pdf',
    [2, 3, ['page' => 4, 'label' => 'Oficina — revisão'], 5],
    4,
    ['width' => 600, 'height' => 400, 'back_rotation' => 0],
    ['root' => '/tmp/saida-cadernos', 'name' => 'manual']
);
echo $resultado['directory'];
```

Crie a raiz de saída antes. Fontes devem ser arquivos locais regulares fora da raiz de saída, sem componentes simbólicos no caminho. Ordem e repetições são preservadas; cada ocorrência possui ordinal próprio. Rótulos UTF-8 são metadados, nunca nomes de arquivo nem texto reencodificado no PDF.

```sh
mkdir /tmp/saida-cadernos
php bin/booklet.php impose --input /tmp/fonte.pdf --pages 2-5 --signature 4 --width 600 --height 400 --root /tmp/saida-cadernos --name manual --json
php bin/booklet.php recover --root /tmp/saida-cadernos --json
```

Intervalos são inclusivos e começam em 1. Nomes existentes não são sobrescritos. A recuperação remove somente diretórios temporários marcados e pertencentes a esta aplicação/raiz, sob a mesma trava. Leitores devem consumir apenas diretórios com `.booklet-complete.json`, nunca `.booklet-stage-*`.

## Decisões de projeto

FPDI/FPDF importam conteúdo e calculam dimensões conforme CropBox e rotação da fonte. qpdf inspeciona todos os objetos, inclusive não referenciados, antes de desativar object streams para o parser gratuito do FPDI. Poppler extrai texto e rasteriza cada lado de forma independente; GD verifica dimensões e pixels. O supervisor Linux encerra o grupo de processos antes de recolher seu líder. As ferramentas são sequenciais.

Para páginas 1–5 em caderno de 8: `[branco,1] [2,branco] [branco,3] [4,5]`. Branco usa ocorrência `null` e `blank: true`, nunca página zero. Fonte quadrada de 200 pt em folha de 600 × 400 pt ocupa 300 × 300 pt, topo 50, esquerda 0 ou 300.

Coordenadas do mapa têm origem superior esquerda e unidade ponto. O verso é descrito pela sua própria vista, não através do papel. Rotação opcional de 180 graus afeta o verso inteiro. Não se infere configuração duplex, manuseio da folha ou orientação física de encadernação universal.

## Limitações

Limites fixos: entrada 16 MiB, 64 ocorrências selecionadas, 32 lados, orçamento monotônico de 20 segundos, worker PHP com 256 MiB; captura e arquivos nativos limitados. Folhas entre 72 e 1440 pt por dimensão. Limites de páginas, objetos e geometria são avaliados **após** parsers maduros, não garantem memória antes do parser. Não existe sandbox; I/O ininterruptível do kernel pode atrasar o encerramento.

O subconjunto estático é conservador: rejeita criptografia, anotações, formulários, ações, anexos, camadas, destinos nomeados e contornos. Não é sanitizador PDF, certificação, validação de acessibilidade ou garantia de qualidade gráfica/impressão. Glifos da fonte permanecem no conteúdo importado; apenas os marcadores sintéticos usam Helvetica interna.

A publicação pressupõe sistema de arquivos local cooperativo, sem alterações concorrentes hostis na raiz ou na fonte. A trava cobre o fluxo inteiro e o rename atômico, mas não oferece transação durável após queda de energia. Limites de admissão não são quota física de diretório. O mapa preserva coordenadas binary64; tolerância de serialização PDF: 0,02 pt. Não há promessa de PDF idêntico entre versões de ferramentas.

Contrato completo em inglês: [docs/contract.md](docs/contract.md). Fundamentação: [docs/design.md](docs/design.md). Versões e proveniência: [docs/provenance.md](docs/provenance.md).

Relatos de segurança: [canal privado do GitHub](https://github.com/martinlofranodeoliveira/booklet-imposition-pack/security/advisories/new). Contato geral: [site oficial](https://lofranotecnologia.com.br).
