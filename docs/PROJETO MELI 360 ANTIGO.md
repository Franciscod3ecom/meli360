USER
// meli 360  conta user - Versão 2.9 (Script da Planilha do Usuário - Sem ScriptProperties)

/**
 * Script da Planilha do Usuário - Interage com a biblioteca MeliLib
 *
 * @version 2.9 (Sem ScriptProperties para Progresso)
 */

// Adicione a biblioteca MeliLib ao seu projeto (Recursos -> Bibliotecas...)

function onOpen() {
    MeliLib.onOpen();
}

function fazerLogin() {
    MeliLib.login();
}

function buscarMeusAnuncios() {
    MeliLib.buscarAnunciosAtivos();
}

// Funções do menu chamando as funções da biblioteca diretamente:
function importarDetalhesDosMeusAnuncios() {
    MeliLib.importarDetalhes(false); // Chamada direta, passando 'false' para importação.
}

function atualizarDetalhesDosMeusAnuncios() {
    MeliLib.importarDetalhes(true); // Chamada direta, passando 'true' para atualização.
}

function atualizarFretes() {
    MeliLib.verificarBancoDeDadosAntesDeAtualizarFrete();
}

// REMOVER as funções getUserScriptProperties, setUserScriptProperty, getUserScriptProperty e deleteUserScriptProperty
// do script da planilha do usuário.  Elas não são mais necessárias.

MELI_LIB
// meli lib (biblioteca) - Versão 2.11 (Otimizações para Limites de Cota)

/**
 * 🚀 MELI360 - Biblioteca para Integração com o Mercado Livre no Google Sheets
 *
 * @version 2.11 (Otimizações para Limites de Cota)
 */

// 🔧 CONFIGURAÇÕES GLOBAIS DO PROJETO
const CONFIG = {
    CLIENT_ID: '2467871586849719',
    CLIENT_SECRET: '05tMlHaDzDireoHAGgqEncWLUSNKrq2c',
    REDIRECT_URI: 'https://script.google.com/macros/d/1GiB5cxHU0eFnfTN3k3DDM5V0CiJHK7HE4IKbr8I2LUoLR6yTlLqnXK9z/usercallback', //VERIFIQUE
    API_BASE_URL: 'https://api.mercadolibre.com',
    BANCO_DADOS_SHEET: 'Banco de Dados',
    ANUNCIOS_SHEET: 'Anúncios',
    PLANILHA_CENTRAL_ID: "1o36pP_4w7gNaqBjpcG77IhfoqSbAVxYdWvIzmhA0Cmo", //VERIFIQUE
    TOKEN_EXPIRATION_MARGIN: 300,
    MAX_RETRIES: 3,  // Mantém o máximo de retentativas
    RETRY_DELAY: 1000, // Atraso inicial, mas será ajustado pelo backoff exponencial.
    BATCH_SIZE: 50,   // <--- Aumente o tamanho do lote (comece com 50 e ajuste)
    FRETE_REGIOES: {
        "70002900": "Brasília, DF",
        "01001000": "São Paulo, SP",
        "40020210": "Salvador, BA",
        "69005070": "Manaus, AM",
        "90010190": "Porto Alegre, RS"
    },
    LOG_SHEET_NAME: 'Log de Erros',
    LOG_SHEET_MAX_ROWS: 5000,
    LOG_SHEET_HEADERS: ['Timestamp', 'Usuário', 'Função', 'Item ID', 'Mensagem de Erro', 'Stack Trace'],
    SHIPPING_CALCULATION_CURRENCY: 'BRL',
    IMAGEM_LARGURA: 120,
    IMAGEM_ALTURA: 120,
    MENU_NAME: '🚀 MELI360',
    TOKENS_SHEET_NAME: 'Tokens',
    ANUNCIO_IMAGEM_COLUNA: 3,
    DESCRICAO_MAX_LENGTH: 2000,
    API_PHP_URL: 'https://trafegogeolocalizado.com.br/360/api.php', //VERIFIQUE
    TOKEN_CHECK_INTERVAL: 4,
    IMPORT_INTERVAL_MINUTES: 15,  // Aumente o tempo entre execuções do gatilho.
    STATUS_COLUNA: 26,
    PROCESSADOS_COLUNA: 27,
};

// 🔐 GERENCIAMENTO DE AUTENTICAÇÃO (Permanece o mesmo)
function login() {
    const userEmail = Session.getActiveUser().getEmail();
    const apiUrl = `${CONFIG.API_PHP_URL}?login&user_email=${encodeURIComponent(userEmail)}`;
    const html = HtmlService.createHtmlOutput(
        `<p>Clique <a href="${apiUrl}" target="_blank">aqui</a> para autorizar o acesso ao Mercado Livre.</p>`
    );
    SpreadsheetApp.getUi().showModalDialog(html, 'Autorizar Mercado Livre');
    agendarVerificacaoToken();
}

function getAccessToken() {
    const userEmail = Session.getActiveUser().getEmail();
    const sheet = SpreadsheetApp.openById(CONFIG.PLANILHA_CENTRAL_ID).getSheetByName(CONFIG.TOKENS_SHEET_NAME);
    const data = sheet.getDataRange().getValues();

    for (let i = 1; i < data.length; i++) {
        if (data[i][0] === userEmail) {
            const lastUpdated = new Date(data[i][4]);
            const now = new Date();
            const expirationTime = new Date(lastUpdated.getTime() + 6 * 60 * 60 * 1000);

            if (now > new Date(expirationTime.getTime() - CONFIG.TOKEN_EXPIRATION_MARGIN * 1000)) {
                console.log("Token perto de expirar, iniciando renovação...");
                const refreshToken = data[i][3];
                const refreshedToken = refreshAccessToken(refreshToken, userEmail);

                if (refreshedToken) {
                    return refreshedToken;
                } else {
                    console.error("Falha ao renovar o token. Por favor, faça login novamente.");
                    showToast("❌ Falha ao renovar o token. Por favor, faça login novamente.");
                    return null;
                }
            } else {
                console.log("Token de acesso encontrado e válido.");
                return data[i][2];
            }
        }
    }

    showToast("❌ Você não está autenticado no Mercado Livre.");
    return null;
}

function refreshAccessToken(refreshToken, userEmail) {
    const apiUrl = `${CONFIG.API_PHP_URL}?refresh&refresh_token=${encodeURIComponent(refreshToken)}&user_email=${encodeURIComponent(userEmail)}`;
    const options = {
        method: 'get',
        muteHttpExceptions: true
    };

    try {
        const response = UrlFetchApp.fetch(apiUrl, options);
        if (response.getResponseCode() === 200) {
            const data = JSON.parse(response.getContentText());
            if (data.access_token) {
                console.log("Token renovado com sucesso!");
                return data.access_token;
            } else {
                console.error("Erro ao renovar token:", data);
                return null;
            }
        } else {
            console.error("Erro ao chamar API para renovação:", response.getContentText());
            return null;
        }
    } catch (error) {
        console.error("Erro ao renovar token:", error);
        return null;
    }
}

// --- FUNÇÃO getUserId (MODIFICADA - Obtém da Planilha Central) ---
function getUserId() { // Removemos o parâmetro 'token'
    const userEmail = Session.getActiveUser().getEmail();
    const sheet = SpreadsheetApp.openById(CONFIG.PLANILHA_CENTRAL_ID).getSheetByName(CONFIG.TOKENS_SHEET_NAME);
    const userId = getUserIdByEmail(sheet, userEmail); // Usa a função auxiliar

    if (!userId) {
        console.error('❌ Erro: User ID não encontrado na planilha central.');
        showToast("❌ Erro: User ID não encontrado. Faça login novamente."); // Mensagem para o usuário
    }
    return userId;
}

// 🗃️ CONFIGURAÇÃO DAS PLANILHAS (Permanece o mesmo)

function configurarBancoDeDados() {
    const sheetName = CONFIG.BANCO_DADOS_SHEET;
    let sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(sheetName);

    if (!sheet) {
        sheet = SpreadsheetApp.getActiveSpreadsheet().insertSheet(sheetName);
    } else {
        sheet.clear();
    }

    sheet.appendRow(['📦 ID do Item', '✨ Status', '🔄 Em Andamento']);

    const headerRange = sheet.getRange(1, 1, 1, 3);
    headerRange.setFontWeight('bold')
        .setFontSize(12)
        .setHorizontalAlignment('center')
        .setBackground('#0d47a1')
        .setFontColor('white');

    sheet.setColumnWidth(1, 300);
    sheet.setColumnWidth(2, 150);
    sheet.setColumnWidth(3, 150);

    return sheet;
}

function configurarAnuncios() {
    const sheetName = CONFIG.ANUNCIOS_SHEET;
    let sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(sheetName);
    if (!sheet) {
        sheet = SpreadsheetApp.getActiveSpreadsheet().insertSheet(sheetName);
    } else {
        sheet.clear();
    }
    const headers = [
        '📦 ID DO ITEM', '📝 TÍTULO', '🖼️ IMAGEM', '🔖 SKU', '📦 ESTOQUE', '💰 PREÇO', '🟢 HEALTH', '🏷️ TAGS', '📂 CATEGORIA',
        '📏 DIMENSÕES DA CATEGORIA', '🚚 LOGÍSTICAS ACEITAS', '⛔ ME2 RESTRICTIONS', '⚠️ RESTRIÇÃO',
        '📅 ÚLTIMA ATUALIZAÇÃO DAS REGRAS DA CATEGORIA', '💵 PREÇO MÁXIMO DA CATEGORIA', '📦 TIPO DE ENVIO',
        '📦 FRETE GRÁTIS ACIMA DE 79', '💲 CUSTO DO ENVIO LISTADO', '⚖️ PESO FATURÁVEL PARA O ENVIO', '📊 STATUS DO PESO',
        '📍 FRETE PARA BRASÍLIA, DF', '📍 FRETE PARA SÃO PAULO, SP', '📍 FRETE PARA SALVADOR, BA',
        '📍 FRETE PARA MANAUS, AM', '📍 FRETE PARA PORTO ALEGRE, RS', '✨ Status', '📈 Processados'
    ];
    sheet.appendRow(headers);
    const headerRange = sheet.getRange(1, 1, 1, headers.length);
    headerRange.setFontWeight('bold')
        .setFontSize(12)
        .setHorizontalAlignment('center')
        .setBackground('#0d47a1')
        .setFontColor('white');
    sheet.setColumnWidth(CONFIG.ANUNCIO_IMAGEM_COLUNA, CONFIG.IMAGEM_LARGURA);
    sheet.setRowHeight(1, CONFIG.IMAGEM_ALTURA);
    sheet.autoResizeColumns(1, headers.length);
    sheet.setWrap(true);
    return sheet;
}

// 🔍 BUSCA DE ANÚNCIOS E IMPORTAÇÃO DE DETALHES (Permanece o mesmo)
function buscarAnunciosAtivos() {
    const token = getAccessToken();
    if (!token) return;
    const userId = getUserId(); // Sem passar o token
    if (!userId) {
        showToast('❌ Erro: ID do usuário ausente. Faça login novamente.');
        return;
    }
    const bancoDeDadosSheet = configurarBancoDeDados();
    let scrollId = null;
    let itemIds = [];
    let totalFetched = 0;
    let hasMore = true;
    let retries = 0;

    showToast("🔎 Buscando seus anúncios ativos no Mercado Livre...");

    do {
        let url = `${CONFIG.API_BASE_URL}/users/${userId}/items/search?search_type=scan&status=active`;
        if (scrollId) {
            url += `&scroll_id=${scrollId}`;
        }
        const options = {
            method: 'get',
            headers: { Authorization: `Bearer ${token}` },
            muteHttpExceptions: true
        };
        try {
            console.log(`🔎 Buscando anúncios ativos para o usuário ${userId}`);
            const response = UrlFetchApp.fetch(url, options);
            if (response.getResponseCode() === 200) {
                const data = JSON.parse(response.getContentText());
                const fetchedIds = data.results;
                itemIds = itemIds.concat(fetchedIds);
                totalFetched += fetchedIds.length;
                scrollId = data.scroll_id;
                hasMore = data.paging.total > totalFetched && scrollId;
                retries = 0;
                console.log(`Anúncios encontrados até agora: ${totalFetched}`);
                showToast(`Anúncios encontrados: ${totalFetched}`);
            } else {
                throw new Error(`❌ Erro na API: ${response.getContentText()}`);
            }
        } catch (error) {
            console.error(`❌ Erro ao buscar anúncios: ${error.message}`);
            if (retries < CONFIG.MAX_RETRIES) {
                retries++;
                console.log(`Tentando novamente (${retries}/${CONFIG.MAX_RETRIES})...`);
                Utilities.sleep(CONFIG.RETRY_DELAY);
            } else {
                console.error(`Número máximo de tentativas excedido.`);
                registrarErro('buscarAnunciosAtivos', null, error.message, error.stack);
                return;
            }
        }
    } while (hasMore);


    if (itemIds.length === 0) {
        showToast('⚠️ Nenhum anúncio ativo foi encontrado.');
        return;
    }

    const lastRow = bancoDeDadosSheet.getLastRow();
    const existingIds = lastRow > 1 ? bancoDeDadosSheet.getRange(2, 1, lastRow - 1, 1).getValues().flat() : [];
    const newIds = itemIds.filter(id => !existingIds.includes(id));

    if (newIds.length > 0) {
        const rows = newIds.map(id => [id, '', '']);
        bancoDeDadosSheet.getRange(bancoDeDadosSheet.getLastRow() + 1, 1, rows.length, 3).setValues(rows);
        showToast(`✅ Busca finalizada! ${newIds.length} novos IDs de anúncios foram adicionados.`);
    } else {
        showToast('✅ Busca finalizada! Nenhum novo ID de anúncio foi encontrado.');
    }
}

function importarDetalhesDosMeusAnuncios() {
    showToast('Iniciando importação de detalhes dos anúncios. O processo continuará automaticamente.');
    agendarImportacaoAutomatica();
    iniciarImportacaoDetalhes();
}

function atualizarTodosDetalhesAnuncios() {
    showToast('Iniciando atualização de detalhes dos anúncios. O processo continuará automaticamente.');
    agendarImportacaoAutomatica();
    iniciarAtualizacaoDetalhes();
}

function agendarImportacaoAutomatica() {
    if (!gerenciarGatilho('importarDetalhesAnuncios', 'existe')) {
        gerenciarGatilho('importarDetalhesAnuncios', 'criar', 'minutos');
        showToast('Importação automática agendada. Os detalhes dos anúncios serão importados a cada 5 minutos.');
    }
}

function importarDetalhesAnuncios() {
    return iniciarImportacaoDetalhes();
}

function iniciarImportacaoDetalhes() {
    importarDetalhes(false);
}

function iniciarAtualizacaoDetalhes() {
    importarDetalhes(true);
}
// Função: importarDetalhes (MODIFICADA - Sem ScriptProperties, Lock e Lotes na Planilha)
function importarDetalhes(atualizarTudo) {
    const bancoDeDadosSheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.BANCO_DADOS_SHEET);

    // --- VERIFICAÇÃO DO LOCK (usando a planilha) ---
    let importLock = bancoDeDadosSheet.getRange(2, 3).getValue(); // Linha 2, Coluna 3 ("Em Andamento")
    if (importLock === 'Sim') {
        showToast("⚠️ Uma importação já está em andamento. Aguarde.");
        return;
    }

    // --- DEFINIÇÃO DO LOCK (usando a planilha) ---
    bancoDeDadosSheet.getRange(2, 3).setValue('Sim'); // Define "Em Andamento" como "Sim"


    try {
        const token = getAccessToken();
        if (!token) {
            showToast('❌ Você não está autenticado.');
            return;
        }

        const anunciosSheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.ANUNCIOS_SHEET);

        if (!bancoDeDadosSheet || !anunciosSheet) {
            showToast('⚠️ Abas "Banco de Dados" ou "Anúncios" não encontradas.');
            return;
        }

        const data = bancoDeDadosSheet.getRange(2, 1, bancoDeDadosSheet.getLastRow() - 1, 2).getValues();
        const itemIds = data.map(row => row[0]).filter(id => id);
        const statusValues = data.map(row => row[1]);

        if (itemIds.length === 0) {
            showToast('⚠️ Nenhum ID de anúncio encontrado na aba "Banco de Dados".');
             // Liberar o lock antes de retornar
            bancoDeDadosSheet.getRange(2, 3).setValue('');
            return;
        }

        let idsToProcess;

        if (atualizarTudo) {
            idsToProcess = itemIds;
        } else {
            idsToProcess = itemIds.filter((id, index) => !statusValues[index]);
        }

        if (idsToProcess.length === 0) {
            showToast('✅ Todos os anúncios já foram importados/atualizados.');
            gerenciarGatilho('importarDetalhesAnuncios', 'excluir');
            bancoDeDadosSheet.getRange(2, 3).setValue(''); // Libera o lock
            return;
        }

        if (anunciosSheet.getLastRow() === 0) {
            configurarAnuncios();
        }

        // --- LÓGICA DE IMPORTAÇÃO EM LOTES ---
        const totalAnuncios = itemIds.length; // Total de anúncios na conta (todos os IDs)
        let processadosTotal = itemIds.length - idsToProcess.length;    //Anúncios JA processados
        const tamanhoLote = CONFIG.BATCH_SIZE;
        let idsToProcessThisBatch = idsToProcess.slice(0, tamanhoLote); // Pega o primeiro lote
        // --- FIM DA LÓGICA DE IMPORTAÇÃO EM LOTES ---



        const totalToImportThisBatch = idsToProcessThisBatch.length; //Somente os do lote
        let processedCountThisBatch = 0;

        showToast(`✅ Iniciando ${atualizarTudo ? 'atualização' : 'importação'} de ${totalToImportThisBatch} anúncios (Lote ${Math.floor(processadosTotal / tamanhoLote) + 1} de ${Math.ceil(totalAnuncios/tamanhoLote)}).`); // Mensagem mais informativa


        for (let i = 0; i < idsToProcessThisBatch.length; i++) {
            const itemId = idsToProcessThisBatch[i];
            const bdRowIndex = itemIds.indexOf(itemId) + 2;
            const rowIndex = atualizarTudo ? (itemIds.indexOf(itemId) + 2) : (anunciosSheet.getLastRow() + 1);

            if (!itemId) {
                console.error(`❌ Item ID inválido na linha ${rowIndex}`);
                anunciosSheet.getRange(rowIndex, CONFIG.STATUS_COLUNA).setValue('❌ ID Inválido');
                continue;
            }

            let retries = 0;
            let success = false;

            while (retries < CONFIG.MAX_RETRIES && !success) {
                try {
                    console.log(`Buscando detalhes do anúncio ${itemId}`);
                    anunciosSheet.getRange(rowIndex, CONFIG.STATUS_COLUNA).setValue('🔄 Buscando dados...');

					const itemData = getItemData(itemId, token);
                    //Verificação se a resposta foi nula.
                    if(!itemData){
                        throw new Error ("Falha ao obter dados do item, resposta da API: " + itemData)
                    }
                    const categoriaDetalhes = obterDetalhesCategoria(itemData.category_id, token);
                     //Verificação se a resposta foi nula.
                    if(!categoriaDetalhes){
                        throw new Error ("Falha ao obter detalhes da categoria, resposta da API: " + categoriaDetalhes)
                    }

                    const envioParaBrasil = obterEnvioParaBrasil(itemId); // Removido token
                    const tags = (itemData.tags || [])
                        .filter(tag => !tag.startsWith('promotion_'))
                        .join('\n');
                    const categoriaFormatada = `ID: ${itemData.category_id}\nNome: ${categoriaDetalhes.nome}`;
                    const dimensoes = categoriaDetalhes.dimensoes
                        ? `📏 Altura: ${categoriaDetalhes.dimensoes.height}\n📏 Largura: ${categoriaDetalhes.dimensoes.width}\n📏 Comprimento: ${categoriaDetalhes.dimensoes.length}\n⚖️ Peso: ${categoriaDetalhes.dimensoes.weight}g`
                        : 'N/A';
                    const logisticas = (categoriaDetalhes.logistics || [])
                        .map(log => `🚛 Tipo: ${log.types.join(', ')}\n🔄 Modo: ${log.mode}`)
                        .join('\n\n');
                    const precoMedioCategoria = calcularFreteCategoria(categoriaDetalhes.dimensoes, itemData);  // Removido token
                    const tipoDeEnvio = itemData.shipping?.mode === 'me2'
                        ? (itemData.shipping?.free_methods ? 'Frete Grátis (Mercado Envios)' : 'Mercado Envios')
                        : 'A combinar com o vendedor';

                    const rowData = [
                        [
                            itemData.id || '',
                            itemData.title || '',
                            `=IMAGE("${itemData.pictures?.[0]?.url}"; 1)`,
                            itemData.seller_custom_field || '',
                            itemData.available_quantity || 0,
                            itemData.price || 'N/A',
                            itemData.health || 'N/A',
                            tags || 'N/A',
                            categoriaFormatada || 'N/A',
                            dimensoes || 'N/A',
                            logisticas || 'N/A',
                            JSON.stringify(categoriaDetalhes.me2_restrictions || []),
                            categoriaDetalhes.restricted ? 'Sim' : 'Não',
                            categoriaDetalhes.last_modified || 'N/A',
                            precoMedioCategoria,
                            tipoDeEnvio,
                            itemData.shipping?.free_shipping ? 'Sim' : 'Não',
                            envioParaBrasil.custo,
                            envioParaBrasil.peso,
                            compararPeso(dimensoes, envioParaBrasil.peso),
                            obterFreteParaRegiao(itemId, "70002900") || 'N/A',  // Removido token
                            obterFreteParaRegiao(itemId, "01001000") || 'N/A', // Removido token
                            obterFreteParaRegiao(itemId, "40020210") || 'N/A',  // Removido token
                            obterFreteParaRegiao(itemId, "69005070") || 'N/A',  // Removido token
                            obterFreteParaRegiao(itemId, "90010190") || 'N/A'  // Removido token
                        ]
                    ];

                    anunciosSheet.getRange(rowIndex, 1, 1, rowData[0].length).setValues(rowData);

                    if (itemData.pictures && itemData.pictures.length > 0) {
                        anunciosSheet.setRowHeight(rowIndex, CONFIG.IMAGEM_ALTURA);
                        anunciosSheet.setColumnWidth(CONFIG.ANUNCIO_IMAGEM_COLUNA, CONFIG.IMAGEM_LARGURA);
                    }

                    console.log(`Dados do anúncio ${itemId} ${atualizarTudo ? 'atualizados' : 'importados'}.`);
                    success = true;
                    processedCountThisBatch++;
                    processadosTotal++; // Incrementa o total de processados
                    anunciosSheet.getRange(rowIndex, CONFIG.STATUS_COLUNA).setValue('✅ Sucesso');
                    bancoDeDadosSheet.getRange(bdRowIndex, 2).setValue('✅ Importado');
                    retries = 0;
                    // REMOVIDO: Utilities.sleep(200);  Pausa *após* o lote, não por item.

                } catch (error) {
					//Mesmo tratamento de erro, porém agora fora do While.
                    console.error(`Erro ao processar anúncio ${itemId}: ${error.message}`);
                    anunciosSheet.getRange(rowIndex, CONFIG.STATUS_COLUNA).setValue(`❌ Erro: ${error.message}`);
                    if (retries < CONFIG.MAX_RETRIES) {
                        retries++;
                        console.log(`Tentando novamente (${retries}/${CONFIG.MAX_RETRIES})...`);
                        Utilities.sleep(CONFIG.RETRY_DELAY);
                    } else {
                        console.error(`Número máximo de tentativas excedido para o anúncio ${itemId}.`);
                        registrarErro('importarDetalhes', itemId, error.message, error.stack);
                        gerenciarGatilho('importarDetalhesAnuncios', 'excluir');
                        showToast("❌ Erro crítico na importação. A importação automática foi desativada. Verifique o Log de Erros.");
                        break;
                    }
                }
            }
			//Atualiza a contagem e a mensagem
            anunciosSheet.getRange(1, CONFIG.PROCESSADOS_COLUNA).setValue(`Processados: ${processadosTotal} / ${totalAnuncios}`);
        }

        // --- PAUSA APÓS O LOTE ---
        Utilities.sleep(2000); // Pausa de 2 segundos após cada lote (ajuste conforme necessário).

        const newData = bancoDeDadosSheet.getRange(2, 1, bancoDeDadosSheet.getLastRow() - 1, 2).getValues();
        const newStatusValues = newData.map(row => row[1]);
        const remainingIds = itemIds.filter((id, index) => !newStatusValues[index]);

        if (remainingIds.length > 0) {
            showToast(`ℹ️ A importação/atualização continuará em breve.  ${remainingIds.length} anúncios restantes.`);
            agendarImportacaoAutomatica();
        } else {
            showToast(`✅ Todos os anúncios foram importados/atualizados! (${totalAnuncios} no total)`);
            gerenciarGatilho('importarDetalhesAnuncios', 'excluir');
        }

    } catch (error) {
        console.error(`Erro inesperado em importarDetalhes: ${error.message}`, error.stack);
        showToast(`Erro inesperado: ${error.message}. Verifique o Log de Erros.`);
        registrarErro('importarDetalhes', null, error.message, error.stack);

    } finally {
        // --- LIBERAÇÃO DO LOCK (usando a planilha) ---
        bancoDeDadosSheet.getRange(2, 3).setValue(''); // Limpa "Em Andamento"
    }
}

// 🔍 OBTENÇÃO DE INFORMAÇÕES DO MERCADO LIVRE (FUNÇÕES AUXILIARES) - COM BACKOFF EXPONENCIAL

function getItemData(itemId, token) {
    if (!itemId || typeof itemId !== 'string') {
        console.error('getItemData: itemId inválido:', itemId);
        return null;
    }
    const url = `${CONFIG.API_BASE_URL}/items/${itemId}`;
    const options = {
        method: 'get',
        headers: { Authorization: `Bearer ${token}` },
        muteHttpExceptions: true  // Importante para não interromper em caso de erro
    };

    let retries = 0;
    let delay = 1000; // Começa com 1 segundo

    while (retries < CONFIG.MAX_RETRIES) {
        try {
            const response = UrlFetchApp.fetch(url, options);
            const statusCode = response.getResponseCode();

            if (statusCode === 200) {
                return JSON.parse(response.getContentText());
            } else if (statusCode === 429) { // Too Many Requests
                retries++;
                console.warn(`Rate limit excedido (429). Tentando novamente em ${delay/1000} segundos...`);
                Utilities.sleep(delay);
                delay *= 2;  // Dobra o tempo de espera a cada tentativa (backoff exponencial)

                //Obtem o retry-after. Se a resposta não contiver, usar o backoff exponencial.
                const retryAfterHeader = response.getHeaders()['Retry-After'];
                if(retryAfterHeader){
                    const retryAfterSeconds = parseInt(retryAfterHeader, 10);
                    if(!isNaN(retryAfterSeconds)){
                        console.log(`Retry-After header: ${retryAfterSeconds} segundos`);
                        Utilities.sleep(retryAfterSeconds * 1000); //Pausa
                        delay = retryAfterSeconds * 1000; //Reseta o delay
                    }
                }

            } else {
                console.error(`Erro ao buscar detalhes do item ${itemId}: ${response.getContentText()}`);
                registrarErro('getItemData', itemId, 'Erro na API de itens', response.getContentText());
                return null;
            }
        } catch (error) {
            console.error(`Erro ao obter detalhes do item: ${error.message}`);
            registrarErro('getItemData', itemId, error.message, error.stack);
             retries++; //Incrementar retries em caso de outros erros.
            if(retries < CONFIG.MAX_RETRIES){
                Utilities.sleep(delay); //Pausa antes de uma nova tentativa
                delay *= 2;
            } else{
                return null; //Se atingiu o limite retorna null
            }

        }
    }
     console.error(`Número máximo de tentativas excedido para o item: ${itemId}.`);
     return null; //Se atingiu o limite retorna null
}

//Função auxiliar COM BACKOFF
function obterDetalhesCategoria(categoriaId, token) {
    if (!categoriaId) {
        console.error('❌ Erro: Categoria ID ausente.');
        return { nome: 'N/A', dimensoes: 'N/A', logistics: [], me2_restrictions: [], restricted: 'N/A', last_modified: 'N/A' };
    }
    const url = `${CONFIG.API_BASE_URL}/categories/${categoriaId}/shipping_preferences`;
    //Adicionado  headers: { Authorization: `Bearer ${token}` }, e  muteHttpExceptions: true
    const options = {
        method: 'get',
        headers: { Authorization: `Bearer ${token}` },
        muteHttpExceptions: true
    };

     //Backoff Exponencial
     let retries = 0;
     let delay = 1000;

    while (retries < CONFIG.MAX_RETRIES) {
        try {
            console.log(`Buscando detalhes da categoria ${categoriaId}`);
            const response = UrlFetchApp.fetch(url, options);
             const statusCode = response.getResponseCode();
            if (statusCode === 200) {
                const data = JSON.parse(response.getContentText());
                const nomeCategoria = obterNomeCategoria(categoriaId, token);
                return {
                    nome: nomeCategoria || 'N/A',
                    dimensoes: data.dimensions || 'N/A',
                    logistics: data.logistics || [],
                    me2_restrictions: data.me2_restrictions || [],
                    restricted: data.restricted ? 'Sim' : 'Não',
                    last_modified: data.last_modified || 'N/A'
                };
                //Se ocorrer 429
            }else if (statusCode === 429) { // Too Many Requests
                retries++;
                console.warn(`Rate limit excedido (429). Tentando novamente em ${delay/1000} segundos...`);
                Utilities.sleep(delay);
                delay *= 2;

                //Obtem o retry-after. Se a resposta não contiver, usar o backoff exponencial.
                const retryAfterHeader = response.getHeaders()['Retry-After'];
                if(retryAfterHeader){
                    const retryAfterSeconds = parseInt(retryAfterHeader, 10);
                    if(!isNaN(retryAfterSeconds)){
                        console.log(`Retry-After header: ${retryAfterSeconds} segundos`);
                        Utilities.sleep(retryAfterSeconds * 1000); //Pausa
                        delay = retryAfterSeconds * 1000; //Reseta o delay
                    }
                }

            } else {
                console.error(`Erro ao buscar detalhes da categoria ${categoriaId}: ${response.getContentText()}`);
                return { nome: 'N/A', dimensoes: 'N/A', logistics: [], me2_restrictions: [], restricted: 'N/A', last_modified: 'N/A' };
            }
        } catch (error) {
            console.error(`Erro ao obter detalhes da categoria: ${error.message}`);
            //Adicionado
            retries++;
            if(retries < CONFIG.MAX_RETRIES){
                Utilities.sleep(delay);
                delay *= 2;
            } else{
                return { nome: 'N/A', dimensoes: 'N/A', logistics: [], me2_restrictions: [], restricted: 'N/A', last_modified: 'N/A' };
            }
        }
    }
    //Se atingir o máximo de tentativas
    console.error(`Número máximo de tentativas excedido ao obter detalhes da categoria ${categoriaId}.`);
    return { nome: 'N/A', dimensoes: 'N/A', logistics: [], me2_restrictions: [], restricted: 'N/A', last_modified: 'N/A' };
}

//Função auxiliar COM BACKOFF
function obterNomeCategoria(categoriaId, token) {
    if (!categoriaId) {
        console.error('❌ Erro: Categoria ID ausente.');
        return 'N/A';
    }
    const url = `${CONFIG.API_BASE_URL}/categories/${categoriaId}`;
     //Adicionado  headers: { Authorization: `Bearer ${token}` }, e  muteHttpExceptions: true
    const options = {
        method: 'get',
        headers: { Authorization: `Bearer ${token}` },
        muteHttpExceptions: true
    };

     //Backoff Exponencial
     let retries = 0;
     let delay = 1000;

    while(retries < CONFIG.MAX_RETRIES){
        try {
            console.log(`Buscando nome da categoria ${categoriaId}`);
            const response = UrlFetchApp.fetch(url, options);
            const statusCode = response.getResponseCode();
            if (statusCode === 200) {
                const data = JSON.parse(response.getContentText());
                return data.name || 'N/A';
            }
            else if (statusCode === 429) { // Too Many Requests
                            retries++;
                console.warn(`Rate limit excedido (429). Tentando novamente em ${delay/1000} segundos...`);
                Utilities.sleep(delay);
                delay *= 2;

                //Obtem o retry-after. Se a resposta não contiver, usar o backoff exponencial.
                const retryAfterHeader = response.getHeaders()['Retry-After'];
                if(retryAfterHeader){
                    const retryAfterSeconds = parseInt(retryAfterHeader, 10);
                    if(!isNaN(retryAfterSeconds)){
                        console.log(`Retry-After header: ${retryAfterSeconds} segundos`);
                        Utilities.sleep(retryAfterSeconds * 1000); //Pausa
                        delay = retryAfterSeconds * 1000; //Reseta o delay
                    }
                }

            }  else {
                console.error(`Erro ao buscar nome da categoria ${categoriaId}: ${response.getContentText()}`);
                return 'N/A';
            }
        } catch (error) {
            console.error(`Erro ao obter nome da categoria: ${error.message}`);
             //Adicionado
            retries++;
            if(retries < CONFIG.MAX_RETRIES){
                Utilities.sleep(delay);
                delay *= 2;
            }else{
                return 'N/A';
            }
        }
    }
     //Se atingir o máximo de tentativas
    console.error(`Número máximo de tentativas excedido para obter o nome da categoria: ${categoriaId}.`);
    return 'N/A'; // Retorna 'N/A' em caso de erro.
}

//Função com backoff e userId vindo da planilha central
function obterEnvioParaBrasil(itemId) { // Removido o parâmetro 'token'
    const userId = getUserId(); // Obtém o User ID da planilha central
    if (!userId) {
        return { custo: 'N/A', peso: 'N/A' }; // Já tratamos o erro em getUserId()
    }
    if (!itemId || typeof itemId !== 'string') {
        console.error('Erro: Item ID inválido.', userId, itemId);
        return { custo: 'N/A', peso: 'N/A' };
    }
    const url = `${CONFIG.API_BASE_URL}/users/${userId}/shipping_options/free?item_id=${itemId}`;
    const options = {
        method: 'get',
        headers: { Authorization: `Bearer ${getAccessToken()}` }, // Usando getAccessToken para a autenticação
        muteHttpExceptions: true
    };

    //Backoff
    let retries = 0;
    let delay = 1000;
    while(retries < CONFIG.MAX_RETRIES){
        try {
            console.log(`Buscando envio para o Brasil para o item ${itemId}`);
            const response = UrlFetchApp.fetch(url, options);
            const statusCode = response.getResponseCode();
            if (statusCode === 200) {
                const data = JSON.parse(response.getContentText());
                const custoEnvio = data.coverage?.all_country?.list_cost || 'N/A';
                const pesoFaturavel = data.coverage?.all_country?.billable_weight || 'N/A';
                return {
                    custo: custoEnvio !== 'N/A' ? `R$ ${parseFloat(custoEnvio).toFixed(2)}` : 'N/A',
                    peso: pesoFaturavel !== 'N/A' ? `${pesoFaturavel}g` : 'N/A'
                };
            }
            else if(statusCode === 429){
                retries++;
                console.warn(`Rate limit excedido (429). Tentando novamente em ${delay/1000} segundos...`);
                Utilities.sleep(delay);
                delay *= 2;
                const retryAfterHeader = response.getHeaders()['Retry-After'];
                if(retryAfterHeader){
                    const retryAfterSeconds = parseInt(retryAfterHeader, 10);
                    if(!isNaN(retryAfterSeconds)){
                        console.log(`Retry-After header: ${retryAfterSeconds} segundos`);
                        Utilities.sleep(retryAfterSeconds * 1000);
                        delay = retryAfterSeconds * 1000;
                    }
                }
            }
            else {
                console.error(`Erro ao buscar envio para o item ${itemId}: ${response.getContentText()}`);
                registrarErro('obterEnvioParaBrasil', itemId, 'Erro na API de envio', response.getContentText());
                return { custo: 'N/A', peso: 'N/A' };
            }
    } catch (error) {
            console.error(`Erro ao obter envio para o Brasil: ${error.message}`);
            registrarErro('obterEnvioParaBrasil', itemId, error.message, error.stack);
            retries++; //Incrementar retries em caso de outros erros.
            if(retries < CONFIG.MAX_RETRIES){
                Utilities.sleep(delay); //Pausa antes de uma nova tentativa
                delay *= 2;
            } else{
                return { custo: 'N/A', peso: 'N/A' };
            }
        }
    }
    console.error(`Número máximo de tentativas excedido para o obterEnvioParaBrasil: ${itemId}.`);
    return { custo: 'N/A', peso: 'N/A' }
}

//Função com userId vindo da planilha
function obterEnvioParaRegioes(itemId) { // Removido o parâmetro 'token'
    const userId = getUserId(); // Obtém o User ID da planilha central
    if (!userId) {
        return {};  // Já tratamos o erro em getUserId()
    }
    if (!itemId || typeof itemId !== 'string') {
        console.error('❌ Erro: Item ID inválido.', userId, itemId);
        return {};
    }
    let freteRegioes = {};
    for (const [zipCode, cidade] of Object.entries(CONFIG.FRETE_REGIOES)) {
        const frete = obterFreteParaRegiao(itemId, zipCode); // Passa *apenas* itemId e zipCode
        freteRegioes[cidade] = frete;
    }
    return freteRegioes;
}

//Função com backoff e userId vindo da planilha
function obterFreteParaRegiao(itemId, zipCode) { // Removido o parâmetro 'token'
    const userId = getUserId(); // Obtém o User ID da planilha central
    if (!userId) {
        return 'N/A'; // Já tratamos o erro em getUserId()
    }
    if (!itemId || typeof itemId !== 'string' || !zipCode || typeof zipCode !== 'string') {
        console.error('❌ Erro: Item ID ou CEP inválido.', userId, itemId, zipCode);
        return 'N/A';
    }
    const url = `${CONFIG.API_BASE_URL}/items/${itemId}/shipping_options?zip_code=${zipCode}`;
    const options = {
        method: 'get',
        headers: { Authorization: `Bearer ${getAccessToken()}` }, // Usando getAccessToken para a autenticação
        muteHttpExceptions: true
    };

    //Backoff
    let retries = 0;
    let delay = 1000;

    while(retries < CONFIG.MAX_RETRIES){
        try {
            console.log(`Buscando frete para o CEP: ${zipCode}`);
            const response = UrlFetchApp.fetch(url, options);
            const statusCode = response.getResponseCode();
            if (statusCode === 200) {
                const data = JSON.parse(response.getContentText());
                const custoFrete = data.options?.[0]?.list_cost || 'N/A';
                return custoFrete !== 'N/A' ? `R$ ${parseFloat(custoFrete).toFixed(2)}` : 'N/A';
            } else if(statusCode === 429){
                retries++;
                console.warn(`Rate limit excedido (429). Tentando novamente em ${delay/1000} segundos...`);
                Utilities.sleep(delay);
                delay *= 2;

                const retryAfterHeader = response.getHeaders()['Retry-After'];
                if(retryAfterHeader){
                    const retryAfterSeconds = parseInt(retryAfterHeader, 10);
                    if(!isNaN(retryAfterSeconds)){
                        console.log(`Retry-After header: ${retryAfterSeconds} segundos`);
                        Utilities.sleep(retryAfterSeconds * 1000); //Pausa
                        delay = retryAfterSeconds * 1000; //Reseta o delay
                    }
                }
            }

            else {
                console.error(`Erro ao buscar envio para ${zipCode}: ${response.getContentText()}`);
                return 'N/A';
            }
        } catch (error) {
            console.error(`Erro ao obter envio para ${zipCode}: ${error.message}`);
            retries++; //Incrementar retries em caso de outros erros.
            if(retries < CONFIG.MAX_RETRIES){
                Utilities.sleep(delay); //Pausa antes de uma nova tentativa
                delay *= 2;
            }else{
                return 'N/A';
            }
        }
    }
    console.error(`Número máximo de tentativas excedido obterFreteParaRegiao para o item e CEP: ${itemId} - ${zipCode}.`);
    return 'N/A';
}

//Função com backoff e userId vindo da planilha
function calcularFreteCategoria(dimensions, itemData) { // Removido o parâmetro 'token'
    const userId = getUserId(); // Obtém o User ID da planilha central
    if (!userId) {
        return 'N/A'; // Já tratamos o erro em getUserId()
    }
    if (!dimensions || !dimensions.height || !dimensions.width || !dimensions.length || !dimensions.weight) {
        console.error('Dimensões inválidas:', JSON.stringify(dimensions));
        return 'N/A';
    }
    const dimString = `${dimensions.height}x${dimensions.width}x${dimensions.length},${dimensions.weight}`;
    const itemPrice = itemData.price || 100;
    const listingType = itemData.listing_type_id || 'gold_pro';
    const mode = itemData.shipping?.mode || 'me2';
    const condition = itemData.condition || 'new';
    const logisticType = itemData.shipping?.logistic_type || 'drop_off';
    const categoryId = itemData.category_id;
     //URL
    const url = `${CONFIG.API_BASE_URL}/users/${userId}/shipping_options/free?dimensions=${dimString}&verbose=true&item_price=${itemPrice}&listing_type_id=${listingType}&mode=${mode}&condition=${condition}&logistic_type=${logisticType}&category_id=${categoryId}&currancy_id=${CONFIG.SHIPPING_CALCULATION_CURRENCY}&seller_status=platinum&reputation=green`;
    const options = {
        method: 'get',
        headers: { Authorization: `Bearer ${getAccessToken()}` }, //Continua usando o token para autenticação
        muteHttpExceptions: true
    };

    //Backoff Exponencial
    let retries = 0;
    let delay = 1000;

    while(retries < CONFIG.MAX_RETRIES){
        try {
            console.log(`Calculando frete para dimensões: ${dimString}`);
            //Faz a requisição
            const response = UrlFetchApp.fetch(url, options);
            const statusCode = response.getResponseCode();
            //Verifica se a requisição foi bem sucedida
            if (statusCode === 200) {
                const data = JSON.parse(response.getContentText());
                const precoMedio = data.coverage?.all_country?.list_cost || 'N/A';
                return precoMedio !== 'N/A' ? `R$ ${parseFloat(precoMedio).toFixed(2)}` : 'N/A';
            }
            //Verifica se ocorreu limite de requisição
            else if(statusCode === 429){
                retries++;
                console.warn(`Rate limit excedido (429). Tentando novamente em ${delay/1000} segundos...`);
                Utilities.sleep(delay);
                delay *= 2; //Backoff Exponencial

                //Obtem o retry-after. Se a resposta não contiver, usar o backoff exponencial.
                const retryAfterHeader = response.getHeaders()['Retry-After'];
                if(retryAfterHeader){
                    const retryAfterSeconds = parseInt(retryAfterHeader, 10);
                    if(!isNaN(retryAfterSeconds)){
                        console.log(`Retry-After header: ${retryAfterSeconds} segundos`);
                        Utilities.sleep(retryAfterSeconds * 1000);
                        delay = retryAfterSeconds * 1000;
                    }
                }
            }
            //Se ocorrer outro erro
            else {
                console.error(`Erro ao calcular frete: ${response.getContentText()}`);
                registrarErro('calcularFreteCategoria', null, 'Erro na API de frete', response.getContentText());
                return 'N/A';
            }
        } catch (error) {
            console.error(`Erro ao calcular frete: ${error.message}`);
            registrarErro('calcularFreteCategoria', null, error.message, error.stack);
            //Adicionado incremento + tratamento.
            retries++;
            if(retries < CONFIG.MAX_RETRIES){
                Utilities.sleep(delay);
                delay *= 2;
            }else{
                return 'N/A';
            }
        }
    }
    console.error(`Número máximo de tentativas excedido para calcularFreteCategoria.`);
    return 'N/A';
}

function atualizarFreteRegioesNaPlanilha() {
    const token = getAccessToken();  // Ainda precisamos do token aqui
    if (!token) return;
    const spreadsheet = SpreadsheetApp.getActiveSpreadsheet();
    const bancoDeDadosSheet = spreadsheet.getSheetByName(CONFIG.BANCO_DADOS_SHEET);
    const anunciosSheet = spreadsheet.getSheetByName(CONFIG.ANUNCIOS_SHEET);

    if (!bancoDeDadosSheet || !anunciosSheet) {
        showToast('❌ Abas "Banco de Dados" ou "Anúncios" não encontradas.');
        return;
    }

    if (bancoDeDadosSheet.getLastRow() > 1) {
        const itemIdsRange = bancoDeDadosSheet.getRange(2, 1, bancoDeDadosSheet.getLastRow() - 1, 1);
        const itemIds = itemIdsRange.getValues().flat().filter(id => id !== '');

        if (itemIds.length === 0) {
            showToast('❌ Nenhum ID válido foi encontrado no Banco de Dados.');
            return;
        }

        itemIds.forEach((itemId, index) => {
            const rowIndex = index + 2;
            const freteRegioes = obterEnvioParaRegioes(itemId); // Passa *apenas* itemId
            anunciosSheet.getRange(rowIndex, 21).setValue(freteRegioes["Brasília, DF"] || 'N/A');
            anunciosSheet.getRange(rowIndex, 22).setValue(freteRegioes["São Paulo, SP"] || 'N/A');
            anunciosSheet.getRange(rowIndex, 23).setValue(freteRegioes["Salvador, BA"] || 'N/A');
            anunciosSheet.getRange(rowIndex, 24).setValue(freteRegioes["Manaus, AM"] || 'N/A');
            anunciosSheet.getRange(rowIndex, 25).setValue(freteRegioes["Porto Alegre, RS"] || 'N/A');
        });

        showToast('✅ Fretes para todas as regiões foram atualizados com sucesso!');
    } else {
        showToast('❌ Nenhum ID foi encontrado no Banco de Dados.');
    }
}

//Permanece o Mesmo
function verificarBancoDeDadosAntesDeAtualizarFrete() {
    const bancoDeDadosSheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.BANCO_DADOS_SHEET);
    if (!bancoDeDadosSheet || bancoDeDadosSheet.getLastRow() <= 1) {
        showToast('❌ Nenhum ID válido foi encontrado no Banco de Dados.');
        return;
    }
    const itemIdsRange = bancoDeDadosSheet.getRange(2, 1, bancoDeDadosSheet.getLastRow() - 1, 1);
    const itemIds = itemIdsRange.getValues().flat().filter(id => id);
    if (itemIds.length === 0) {
        showToast('❌ Nenhum ID válido foi encontrado no Banco de Dados.');
        return;
    }
    atualizarFreteRegioesNaPlanilha();
}

// 📊 ANÁLISE DE DADOS (Permanece a mesma)
function compararPeso(dimensoes, pesoFaturavel) {
    console.log("dimensoes:", dimensoes);
    console.log("pesoFaturavel:", pesoFaturavel);
    if (!dimensoes || !pesoFaturavel) {
        console.error("Erro em compararPeso: dimensões ou pesoFaturavel ausentes.");
        return "N/A";
    }
    const pesoCategoriaMatch = dimensoes.match(/⚖️ Peso: (\d+)g/);
    const pesoCategoria = pesoCategoriaMatch ? parseInt(pesoCategoriaMatch[1]) : null;
    const pesoFaturavelNum = parseInt(pesoFaturavel.replace(/[^0-9]/g, ''));
    if (pesoFaturavelNum === pesoCategoria) {
        return "🟡 Peso aceitável";
    } else if (pesoFaturavelNum > pesoCategoria) {
        return "🔴 Peso alto e errado";
    } else {
        return "🏆 🟢 Peso baixo e bom";
    }
}

// 📜 GERENCIAMENTO DE ERROS (Permanece o mesmo)
function abrirLogDeErros() {
    const logSheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(CONFIG.LOG_SHEET_NAME);
    if (logSheet) {
        SpreadsheetApp.setActiveSheet(logSheet);
    } else {
        showToast('⚠️ Nenhum log de erros foi encontrado.');
    }
}

function registrarErro(funcao, itemId, mensagem, stackTrace) {
    const logSheetName = CONFIG.LOG_SHEET_NAME;
    let logSheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(logSheetName);
    const userEmail = Session.getActiveUser().getEmail();

    if (!logSheet) {
        logSheet = SpreadsheetApp.getActiveSpreadsheet().insertSheet(logSheetName);
        logSheet.appendRow(CONFIG.LOG_SHEET_HEADERS);
        const headerRange = logSheet.getRange(1, 1, 1, CONFIG.LOG_SHEET_HEADERS.length);
        headerRange.setFontWeight('bold').setBackground('#ff0000').setFontColor('white');
    }

    if (logSheet.getLastRow() >= CONFIG.LOG_SHEET_MAX_ROWS) {
        logSheet.deleteRows(2, 1);
    }
    logSheet.appendRow([new Date(), userEmail, funcao, itemId || 'N/A', mensagem, stackTrace || 'N/A']);
}

// 🛠️ UTILITÁRIOS (Permanece o mesmo)

function showToast(message) {
    SpreadsheetApp.getActiveSpreadsheet().toast(message);
}

function gerenciarGatilho(funcao, tipo, intervalo = null) {
    const nomeFuncaoCompleto = `MeliLib.${funcao}`;
    const triggers = ScriptApp.getProjectTriggers();
    const gatilhoExistente = triggers.find(trigger => trigger.getHandlerFunction() === nomeFuncaoCompleto);

    switch (tipo) {
        case 'criar':
            if (!gatilhoExistente) {
                let triggerBuilder = ScriptApp.newTrigger(nomeFuncaoCompleto).timeBased();
                if (intervalo === 'minutos') {
                    triggerBuilder = triggerBuilder.everyMinutes(CONFIG.IMPORT_INTERVAL_MINUTES);
                } else if (intervalo === 'horas') {
                    triggerBuilder = triggerBuilder.everyHours(CONFIG.TOKEN_CHECK_INTERVAL);
                }
                triggerBuilder.create();
                console.log(`Gatilho criado para ${nomeFuncaoCompleto}`);
            }
            break;
        case 'excluir':
            if (gatilhoExistente) {
                ScriptApp.deleteTrigger(gatilhoExistente);
                console.log(`Gatilho excluído para ${nomeFuncaoCompleto}`);
            }
            break;
        case 'existe':
            return !!gatilhoExistente;
        default:
            console.warn(`Tipo de gatilho inválido: ${tipo}`);
    }
}

function formatDate(date, formatString) {
    return Utilities.formatDate(date, Session.getTimeZone(), formatString);
}

// Funções para acessar as propriedades do script (armazenamento persistente).
// Usadas *apenas* para o lock geral (se necessário) e configurações da biblioteca.
function getScriptProperties() { return PropertiesService.getScriptProperties(); }
function setScriptProperty(key, value) { PropertiesService.getScriptProperties().setProperty(key, value); }
function getScriptProperty(key) { return PropertiesService.getScriptProperties().getProperty(key); }
function deleteScriptProperty(key) { PropertiesService.getScriptProperties().deleteProperty(key); }

// ⏰ GERENCIAMENTO DE TOKEN (VERIFICAÇÃO E RENOVAÇÃO AUTOMÁTICA) - Permanece o mesmo
function verificarERenovarToken() {
    const userEmail = Session.getActiveUser().getEmail();
    const sheet = SpreadsheetApp.openById(CONFIG.PLANILHA_CENTRAL_ID).getSheetByName(CONFIG.TOKENS_SHEET_NAME);
    const data = sheet.getDataRange().getValues();
    for (let i = 1; i < data.length; i++) {
        if (data[i][0] === userEmail) {
            const lastUpdated = new Date(data[i][4]);
            const now = new Date();
            const expirationTime = new Date(lastUpdated.getTime() + 6 * 60 * 60 * 1000);
            if (now > new Date(expirationTime.getTime() - CONFIG.TOKEN_EXPIRATION_MARGIN * 1000)) {
                console.log("Token perto de expirar, renovando...");
                const refreshToken = data[i][3];
                const refreshedToken = refreshAccessToken(refreshToken, userEmail);
                if (refreshedToken) {
                    showToast("✅ Token renovado com sucesso!");
                } else {
                    console.error("Falha ao renovar o token automaticamente.");
                    registrarErro('verificarERenovarToken', null, 'Falha ao renovar token.', null);
                }
            } else {
                console.log("Token de acesso ainda é válido.");
            }
            return;
        }
    }
    console.error("Nenhum token encontrado para o usuário: " + userEmail);
    registrarErro('verificarERenovarToken', null, 'Nenhum token encontrado.', null);
}

function agendarVerificacaoToken() {
    if (!gerenciarGatilho('verificarERenovarToken', 'existe')) {
        gerenciarGatilho('verificarERenovarToken', 'criar', 'horas');
        console.log('Agendamento da verificação do token realizado com sucesso.');
        showToast('Verificação e renovação do token agendada a cada 4 horas.');
    }
}

// ⚙️ FUNÇÃO onOpen (EXECUTADA QUANDO A PLANILHA É ABERTA) - Permanece a mesma
function onOpen() {
    const ui = SpreadsheetApp.getUi();
    ui.createMenu(CONFIG.MENU_NAME)
        .addItem('🔑 Fazer Login', 'MeliLib.login')
        .addSeparator()
        .addItem('🔍 Buscar Anúncios Ativos', 'MeliLib.buscarAnunciosAtivos')
        .addItem('📦 Importar Detalhes', 'MeliLib.importarDetalhesDosMeusAnuncios')
        .addItem('🔄 Atualizar Detalhes', 'MeliLib.atualizarTodosDetalhesAnuncios')
        .addItem('🚚 Atualizar Frete', 'MeliLib.verificarBancoDeDadosAntesDeAtualizarFrete')
        .addSeparator()
        .addItem('📜 Log de Erros', 'MeliLib.abrirLogDeErros')
        .addSeparator()
        .addItem('🔄 Atualizar Menu', 'MeliLib.onOpen')
        .addToUi();
    agendarVerificacaoToken();
}

// 📩 FUNÇÃO doPost (PARA RECEBER REQUISIÇÕES DO BACKEND PHP) - COM TRATAMENTO DE ERROS APRIMORADO
function doPost(e) {
    try {
        const data = JSON.parse(e.postData.contents);
        const action = data.action;

        if (action === "saveToken") {
            // --- RECEBENDO E SALVANDO O USER ID (MODIFICADO) ---
            const { email, user_id, access_token, refresh_token, last_updated } = data;  // Agora esperamos user_id
            if (email && user_id && access_token && refresh_token) { // Validação inclui user_id
                const sheet = SpreadsheetApp.openById(CONFIG.PLANILHA_CENTRAL_ID).getSheetByName(CONFIG.TOKENS_SHEET_NAME);
                const existingRowIndex = findRowByEmail(sheet, email);

                if (existingRowIndex !== -1) {
                    // Atualiza a linha existente (agora com o user_id na coluna correta)
                    sheet.getRange(existingRowIndex, 2, 1, 4).setValues([[user_id, access_token, refresh_token, last_updated]]);
                    return ContentService.createTextOutput("Tokens atualizados com sucesso!").setMimeType(ContentService.MimeType.TEXT);
                } else {
                    // Adiciona uma nova linha (agora com o user_id)
                    sheet.appendRow([email, user_id, access_token, refresh_token, last_updated]);
                    return ContentService.createTextOutput("Tokens salvos com sucesso!").setMimeType(ContentService.MimeType.TEXT);
                }
            } else {
                return ContentService.createTextOutput("Erro: dados incompletos.").setMimeType(ContentService.MimeType.TEXT);
            }
            // --- FIM DA MODIFICAÇÃO ---
        } else if (action === "getUserId") {
            // ... (mesmo código para getUserId) ...
            //(O getUserId ja está implementado)
             const { email } = data;
            const sheet = SpreadsheetApp.openById(CONFIG.PLANILHA_CENTRAL_ID).getSheetByName(CONFIG.TOKENS_SHEET_NAME);
            const userId = getUserIdByEmail(sheet, email);
            return ContentService.createTextOutput(userId ? JSON.stringify({ userId: userId }) : JSON.stringify({ error: "User ID não encontrado." })).setMimeType(ContentService.MimeType.JSON);

        } else {
            return ContentService.createTextOutput("Erro: ação desconhecida.").setMimeType(ContentService.MimeType.TEXT);
        }
    } catch (error) {
        // REGISTRO DE ERRO DETALHADO no Stackdriver Logging:
        console.error("Erro em doPost():", error, e);  // Inclui o objeto 'e' para mais contexto.
        return ContentService.createTextOutput("Erro interno do servidor: " + error).setMimeType(ContentService.MimeType.TEXT);
    }
}

// Funções auxiliares para a função doPost (Permanecem as mesmas)
function findRowByEmail(sheet, email) {
    const data = sheet.getDataRange().getValues();
    for (let i = 1; i < data.length; i++) {
        if (data[i][0] === email) {
            return i + 1;
        }
    }
    return -1;
}

function getUserIdByEmail(sheet, email) {
    const data = sheet.getDataRange().getValues();
    for (let i = 1; i < data.length; i++) {
        if (data[i][0] === email) {
            return data[i][1];
        }
    }
    return null;
}
// 🎁 EXPORTAÇÃO DAS FUNÇÕES
this.MeliLib = {
    login,
    getAccessToken,
    buscarAnunciosAtivos,
    importarDetalhesAnuncios,
    atualizarFreteRegioesNaPlanilha,
    verificarBancoDeDadosAntesDeAtualizarFrete,
    abrirLogDeErros,
    onOpen,
    obterDetalhesCategoria,
    obterNomeCategoria,
    getItemData,
    obterEnvioParaBrasil,
    obterEnvioParaRegioes,
    obterFreteParaRegiao,
    calcularFreteCategoria,
    getScriptProperties,
    setScriptProperty,
    getScriptProperty,
    deleteScriptProperty,
    formatDate,
    gerenciarGatilho,
    importarDetalhesDosMeusAnuncios,
    atualizarTodosDetalhesAnuncios,
    verificarERenovarToken,
    agendarImportacaoAutomatica,
};



API.PHP

<?php
// api.php - COM ENVIO DO USER ID E LOGGING APRIMORADO

// 🔧 CONFIGURAÇÕES DO MERCADO LIVRE -  VERIFIQUE TODAS AS SUAS CREDENCIAIS E URLS
define("CLIENT_ID", "2467871586849719"); // Substitua pelo seu Client ID
define("CLIENT_SECRET", "05tMlHaDzDireoHAGgqEncWLUSNKrq2c"); // Substitua pelo seu Client Secret
define("REDIRECT_URI", "https://trafegogeolocalizado.com.br/360/api.php"); // Sua URL!
// URL do seu Web App que atualiza a planilha central.  VERIFIQUE SE ESTÁ CORRETA!
define("TOKEN_SHEET_URL", "https://script.google.com/macros/s/AKfycbx72OY18tQI3tlcx6z_-cG3SVSgw-7_3QmFrc8RoH7seCznW2hS0b0TKV4WgpMvtrZ-/exec"); //SUA URL!
define("AUTH_URL", "https://auth.mercadolivre.com.br/authorization");
define("TOKEN_URL", "https://api.mercadolibre.com/oauth/token");

// 🔄 Função para trocar o código de autorização pelo token... (MODIFICADA)
function handleToken($codeOrRefreshToken, $userEmail, $isRefresh = false) {
    // ... (código inicial permanece o mesmo) ...
    $data = [
        "client_id"     => CLIENT_ID,
        "client_secret" => CLIENT_SECRET,
        "redirect_uri"  => REDIRECT_URI
    ];

    if ($isRefresh) {
        $data["grant_type"] = "refresh_token";
        $data["refresh_token"] = $codeOrRefreshToken;
    } else {
        $data["grant_type"] = "authorization_code";
        $data["code"] = $codeOrRefreshToken;
    }

    $ch = curl_init(TOKEN_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $response = json_decode(curl_exec($ch), true);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo json_encode(["error" => "Erro cURL: " . $error_msg]);
        error_log("Erro cURL em handleToken: " . $error_msg);
        exit();
    }
    curl_close($ch);

    if (isset($response['error'])) {
        http_response_code(400);
        echo json_encode(["error" => "Erro do Mercado Livre: " . $response['message']]);
        error_log("Erro do Mercado Livre em handleToken: " . $response['message']);
        exit();
    }
    // --- Fim do Tratamento de Erro ---


    if (isset($response['access_token'])) {
        // --- OBTENÇÃO E ENVIO DO USER ID (MODIFICADO) ---
        if (!$isRefresh) { // Se NÃO for refresh, obtemos o User ID
            $userId = getUserId($response['access_token']);
            if (!$userId) {
                http_response_code(500);
                echo json_encode(["error" => "Não foi possível obter o User ID."]);
                error_log("Não foi possível obter o User ID em handleToken");
                exit();
            }
        } else { // Se for refresh, usamos a função getUserIdFromSheet
            $userId = getUserIdFromSheet($userEmail);
             if (!$userId) { //Verifica se obteve o userID
                                http_response_code(500);
                echo json_encode(["error" => "Não foi possível obter o User ID a partir do email."]);
				error_log("Não foi possível obter o User ID a partir do email em handleToken");
                exit();
            }
        }


        if ($userId) {
            // --- CHAMADA À FUNÇÃO saveTokens (agora com o User ID) ---
             saveTokens($userEmail, $userId, $response['access_token'], $response['refresh_token']);
        }
    }

    return $response;
}

// 🆔 Função para obter o User ID usando o access token (Permanece a mesma)
function getUserId($accessToken) {
	$ch = curl_init("https://api.mercadolibre.com/users/me?access_token=" . $accessToken);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$response = json_decode(curl_exec($ch), true);

	if (curl_errno($ch)) {
		$error_msg = curl_error($ch);
		curl_close($ch);
		http_response_code(500);
		echo json_encode(["error" => "Erro cURL (getUserId): " . $error_msg]);
		error_log("Erro cURL em getUserId: " . $error_msg);
		exit();
	}
	curl_close($ch);

	if (isset($response['error'])) {
		http_response_code(400);
		echo json_encode(["error" => "Erro do Mercado Livre (getUserId): " . $response['message']]);
		error_log("Erro do Mercado Livre em getUserId: " . $response['message']);
		 exit();
	}

	return isset($response['id']) ? $response['id'] : null;
}

// 🆔 Função para obter o User ID da planilha central usando o email (Permanece a mesma)
function getUserIdFromSheet($userEmail) {
	$ch = curl_init(TOKEN_SHEET_URL . "?action=getUserId&email=" . urlencode($userEmail));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$response = json_decode(curl_exec($ch), true);

	if (curl_errno($ch)) {
		$error_msg = curl_error($ch);
		curl_close($ch);
		http_response_code(500);
		echo json_encode(["error" => "Erro cURL (getUserIdFromSheet): " . $error_msg]);
		error_log("Erro cURL em getUserIdFromSheet: " . $error_msg);
		exit();
	}
	curl_close($ch);

	if (isset($response['error'])) {
		http_response_code(400);
		echo json_encode(["error" => "Erro do Google Apps Script (getUserIdFromSheet): " . $response['error']]);
		error_log("Erro do Google Apps Script em getUserIdFromSheet: " . $response['error']);
		exit();
	}

	return isset($response['userId']) ? $response['userId'] : null;
}

// 💾 Função para salvar/atualizar os tokens na planilha central (Permanece a mesma)
function saveTokens($userEmail, $userId, $accessToken, $refreshToken) {
    $payload = json_encode([
        "action"        => "saveToken",
        "email"         => $userEmail,
        "user_id"       => $userId,  // <--- Enviando o User ID
        "access_token"  => $accessToken,
        "refresh_token" => $refreshToken,
        "last_updated"  => date("Y-m-d H:i:s")
    ]);

    $ch = curl_init(TOKEN_SHEET_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        http_response_code(500);
        echo json_encode(["error" => "Erro cURL (saveTokens): " . $error_msg]);
        error_log("Erro cURL em saveTokens: " . $error_msg . " - URL: " . TOKEN_SHEET_URL . " - Payload: " . $payload); // LOG DETALHADO!
        exit();
    }
    curl_close($ch);

    $decodedResponse = json_decode($response, true);
    if (isset($decodedResponse['error'])) {
        http_response_code(500);
        echo json_encode(["error" => "Erro do Google Apps Script (saveTokens): " . $decodedResponse['error']]);
        error_log("Erro do GAS em saveTokens: " . $response . " - URL: " . TOKEN_SHEET_URL . " - Payload: " . $payload); // LOG DETALHADO!
        exit();
    }

    error_log("Sucesso em saveTokens: " . $response . " - URL: " . TOKEN_SHEET_URL . " - Payload: " . $payload); // LOG de sucesso!
    return $response;
}


// 🚀 Ação principal baseada na query string (Permanece a mesma)
if (isset($_GET['login']) && isset($_GET['user_email'])) {
	// 🔑 Iniciar processo de login (redirecionar para o Mercado Livre)
	$userEmail = $_GET['user_email'];
	$params = [
		"response_type" => "code",
		"client_id"     => CLIENT_ID,
		"redirect_uri"  => REDIRECT_URI,
		"state"         => $userEmail // Usar o e-mail como state para vincular a resposta ao usuário
	];
	$authUrl = AUTH_URL . "?" . http_build_query($params);
	header("Location: " . $authUrl);
	exit();

} elseif (isset($_GET['code']) && isset($_GET['state'])) {
	// 🔄 Recebeu o código de autorização, trocar pelo token e salvar
	$code = $_GET['code'];
	$userEmail = $_GET['state']; // O e-mail do usuário foi enviado como state

	$tokenResponse = handleToken($code, $userEmail);

	if (isset($tokenResponse['access_token'])) {
		echo "✅ Autenticação concluída com sucesso! Tokens salvos.";
		// Você pode adicionar um HTML mais amigável aqui ou redirecionar
	} else {
		http_response_code(400); // Bad Request
		echo "❌ Erro ao obter token: " . json_encode($tokenResponse);
	}
} elseif (isset($_GET['refresh']) && isset($_GET['refresh_token']) && isset($_GET['user_email'])) {
	// 🔄 Ação para renovar o token
	$refreshToken = $_GET['refresh_token'];
	$userEmail = $_GET['user_email'];

	$tokenResponse = handleToken($refreshToken, $userEmail, true);

	if (isset($tokenResponse['access_token'])) {
		echo json_encode(["access_token" => $tokenResponse['access_token']]);
	} else {
		http_response_code(400); // Bad Request
		echo json_encode(["error" => "Erro ao renovar o token: " . json_encode($tokenResponse)]);
	}
} else {
	http_response_code(400); //Bad Request
	echo "🤔 Ação inválida.";
}
?>