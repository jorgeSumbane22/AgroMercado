# Validação visual — atualização de assets

Data da validação: 24 de agosto de 2026.

A home foi aberta através do servidor local com o prefixo `/agro-marketplace/`. O hero carregou a fotografia local `assets/img/hero-farm.jpg`. Os seis tiles de categorias passaram a renderizar imagens locais (`arroz.jpg`, `manga.jpg`, `hortalicas.jpg`, `batata.jpg`, `feijao.jpg` e `amendoim.jpg`) com ícone sobreposto. Os oito cartões de produtos continuam a renderizar imagens reais locais. Os quatro cartões de agricultores passaram a renderizar fotografias locais em `assets/img/farmers/`, com badge de verificação por ícone Bootstrap. Não foram observadas imagens partidas na home.

Os estados de pedidos usam agora badges com cor, ícone e texto; os menus/sidebar e botões mantêm ícones Bootstrap. O dashboard do comprador passou a incluir um gráfico de distribuição de pedidos, enquanto os dashboards do agricultor e SuperAdmin já tinham gráficos Chart.js.
