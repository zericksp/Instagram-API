<?php
// ========================================
// web/register.php - SISTEMA WEB DE CADASTRO
//
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Empresa - Instagram Insights</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-pink-50 to-purple-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-pink-500 to-purple-600 rounded-xl mb-4">
                <i class="fas fa-chart-line text-white text-2xl"></i>
            </div>
            <h1 class="text-4xl font-bold text-gray-800 mb-2">Instagram Insights SaaS</h1>
            <p class="text-gray-600">Cadastre sua empresa e conecte sua conta do Instagram</p>
        </div>

        <!-- Stepper -->
        <div class="flex justify-center mb-8">
            <div class="flex items-center space-x-4">
                <div id="step1-indicator" class="flex items-center justify-center w-10 h-10 bg-pink-500 text-white rounded-full font-bold">1</div>
                <div class="w-16 h-1 bg-gray-300" id="line1"></div>
                <div id="step2-indicator" class="flex items-center justify-center w-10 h-10 bg-gray-300 text-gray-600 rounded-full font-bold">2</div>
                <div class="w-16 h-1 bg-gray-300" id="line2"></div>
                <div id="step3-indicator" class="flex items-center justify-center w-10 h-10 bg-gray-300 text-gray-600 rounded-full font-bold">3</div>
            </div>
        </div>

        <!-- Step 1: Dados da Empresa -->
        <div id="step1" class="max-w-2xl mx-auto bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">
                <i class="fas fa-building mr-3 text-pink-500"></i>
                Dados da Empresa
            </h2>
            
            <form id="companyForm" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">CNPJ</label>
                        <input type="text" id="cnpj" name="cnpj" placeholder="00.000.000/0000-00" 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Razão Social</label>
                        <input type="text" id="companyName" name="companyName" placeholder="Empresa LTDA"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nome Fantasia</label>
                        <input type="text" id="fantasyName" name="fantasyName" placeholder="Empresa Digital"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                        <input type="email" id="email" name="email" placeholder="contato@empresa.com"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Telefone</label>
                        <input type="tel" id="phone" name="phone" placeholder="(11) 99999-9999"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Plano</label>
                        <select id="plan" name="plan" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500">
                            <option value="free">Gratuito</option>
                            <option value="basic">Básico - R$ 99/mês</option>
                            <option value="premium" selected>Premium - R$ 199/mês</option>
                            <option value="enterprise">Enterprise - R$ 399/mês</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="button" onclick="nextStep(2)" 
                            class="bg-pink-500 hover:bg-pink-600 text-white px-8 py-3 rounded-lg font-semibold transition-colors">
                        Próximo <i class="fas fa-arrow-right ml-2"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Step 2: Conectar Instagram -->
        <div id="step2" class="max-w-2xl mx-auto bg-white rounded-2xl shadow-xl p-8 hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">
                <i class="fab fa-instagram mr-3 text-pink-500"></i>
                Conectar Instagram
            </h2>
            
            <div class="text-center py-8">
                <div class="bg-gradient-to-r from-purple-500 to-pink-500 rounded-full w-24 h-24 flex items-center justify-center mx-auto mb-6">
                    <i class="fab fa-instagram text-white text-4xl"></i>
                </div>
                
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Autorizar Acesso ao Instagram</h3>
                <p class="text-gray-600 mb-8">Clique no botão abaixo para conectar sua conta Instagram Business ao nosso sistema</p>
                
                <button id="facebookConnectBtn" 
                        class="bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white px-8 py-4 rounded-lg font-semibold transition-all transform hover:scale-105 shadow-lg">
                    <i class="fab fa-facebook mr-3"></i>
                    Conectar com Facebook
                </button>
                
                <div id="connectionStatus" class="mt-6 hidden">
                    <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                        <span class="text-green-700">Instagram conectado com sucesso!</span>
                    </div>
                </div>
            </div>

            <div class="flex justify-between">
                <button type="button" onclick="previousStep(1)" 
                        class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-8 py-3 rounded-lg font-semibold transition-colors">
                    <i class="fas fa-arrow-left mr-2"></i> Anterior
                </button>
                <button type="button" id="step2NextBtn" onclick="nextStep(3)" disabled
                        class="bg-pink-500 hover:bg-pink-600 text-white px-8 py-3 rounded-lg font-semibold transition-colors disabled:bg-gray-300 disabled:cursor-not-allowed">
                    Próximo <i class="fas fa-arrow-right ml-2"></i>
                </button>
            </div>
        </div>

        <!-- Step 3: Criar Usuário -->
        <div id="step3" class="max-w-2xl mx-auto bg-white rounded-2xl shadow-xl p-8 hidden">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">
                <i class="fas fa-user-plus mr-3 text-pink-500"></i>
                Criar Usuário Administrador
            </h2>
            
            <form id="userForm" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nome Completo</label>
                        <input type="text" id="userName" name="userName" placeholder="João Silva"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email de Login</label>
                        <input type="email" id="userEmail" name="userEmail" placeholder="joao@empresa.com"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Senha</label>
                        <input type="password" id="password" name="password" placeholder="Mínimo 8 caracteres"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirmar Senha</label>
                        <input type="password" id="confirmPassword" name="confirmPassword" placeholder="Repetir senha"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-pink-500" required>
                    </div>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-3"></i>
                        <div>
                            <p class="text-blue-800 font-semibold">Usuário Administrador</p>
                            <p class="text-blue-600 text-sm">Este usuário terá acesso total ao sistema e poderá criar outros usuários.</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between">
                    <button type="button" onclick="previousStep(2)" 
                            class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-8 py-3 rounded-lg font-semibold transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i> Anterior
                    </button>
                    <button type="submit" 
                            class="bg-gradient-to-r from-pink-500 to-purple-500 hover:from-pink-600 hover:to-purple-600 text-white px-8 py-3 rounded-lg font-semibold transition-all transform hover:scale-105">
                        <i class="fas fa-check mr-2"></i> Finalizar Cadastro
                    </button>
                </div>
            </form>
        </div>

        <!-- Success Message -->
        <div id="successMessage" class="max-w-2xl mx-auto bg-white rounded-2xl shadow-xl p-8 text-center hidden">
            <div class="bg-green-100 rounded-full w-24 h-24 flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-check text-green-500 text-4xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Cadastro Realizado com Sucesso!</h2>
            <p class="text-gray-600 mb-6">Sua empresa foi cadastrada e sua conta Instagram foi conectada. Você pode agora fazer login no aplicativo.</p>
            
            <div class="bg-gray-50 rounded-lg p-4 mb-6">
                <h3 class="font-semibold text-gray-800 mb-2">Próximos Passos:</h3>
                <ul class="text-left text-gray-600 space-y-2">
                    <li><i class="fas fa-mobile-alt text-pink-500 mr-2"></i> Baixe o aplicativo Instagram Insights</li>
                    <li><i class="fas fa-sign-in-alt text-pink-500 mr-2"></i> Faça login com o email e senha criados</li>
                    <li><i class="fas fa-chart-bar text-pink-500 mr-2"></i> Comece a visualizar suas métricas!</li>
                </ul>
            </div>
            
            <a href="/app" class="bg-pink-500 hover:bg-pink-600 text-white px-8 py-3 rounded-lg font-semibold transition-colors inline-block">
                <i class="fas fa-mobile-alt mr-2"></i> Ir para o App
            </a>
        </div>
    </div>

    <script>
        // JavaScript para controle dos steps e integração Facebook
        function nextStep(step) {
            // Ocultar step atual
            document.querySelectorAll('[id^="step"]').forEach(el => {
                if (!el.id.includes('indicator')) {
                    el.classList.add('hidden');
                }
            });
            
            // Mostrar próximo step
            document.getElementById('step' + step).classList.remove('hidden');
            
            // Atualizar indicadores
            updateStepIndicators(step);
        }
        
        function previousStep(step) {
            nextStep(step);
        }
        
        function updateStepIndicators(currentStep) {
            for (let i = 1; i <= 3; i++) {
                const indicator = document.getElementById('step' + i + '-indicator');
                if (i <= currentStep) {
                    indicator.classList.add('bg-pink-500', 'text-white');
                    indicator.classList.remove('bg-gray-300', 'text-gray-600');
                } else {
                    indicator.classList.add('bg-gray-300', 'text-gray-600');
                    indicator.classList.remove('bg-pink-500', 'text-white');
                }
            }
        }
        
        // Integração com Facebook SDK
        window.fbAsyncInit = function() {
            FB.init({
                appId: 'SEU_FACEBOOK_APP_ID',
                cookie: true,
                xfbml: true,
                version: 'v18.0'
            });
        };
        
        document.getElementById('facebookConnectBtn').addEventListener('click', function() {
            FB.login(function(response) {
                if (response.authResponse) {
                    // Usuário autorizou
                    getInstagramAccounts(response.authResponse.accessToken);
                } else {
                    alert('Autorização cancelada');
                }
            }, {scope: 'pages_show_list,instagram_basic,instagram_manage_insights'});
        });
        
        function getInstagramAccounts(accessToken) {
            // Buscar contas Instagram conectadas
            FB.api('/me/accounts', function(response) {
                if (response.data && response.data.length > 0) {
                    // Processar contas e tokens
                    processInstagramConnection(accessToken, response.data);
                } else {
                    alert('Nenhuma página Facebook encontrada');
                }
            });
        }
        
        function processInstagramConnection(accessToken, pages) {
            // Aqui você processaria a conexão e geraria token de longa duração
            // Por simplicidade, vamos simular sucesso
            document.getElementById('connectionStatus').classList.remove('hidden');
            document.getElementById('step2NextBtn').disabled = false;
            document.getElementById('step2NextBtn').classList.remove('disabled:bg-gray-300');
        }
        
        // Máscara para CNPJ
        document.getElementById('cnpj').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/^(\d{2})(\d)/, '$1.$2');
            value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
            value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
            value = value.replace(/(\d{4})(\d)/, '$1-$2');
            e.target.value = value;
        });
        
        // Validação de senhas
        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            
            if (password !== confirmPassword) {
                alert('As senhas não coincidem');
                return;
            }
            
            if (password.length < 8) {
                alert('A senha deve ter pelo menos 8 caracteres');
                return;
            }
            
            // Aqui faria o cadastro final via AJAX
            finalizeCadastro();
        });
        
        function finalizeCadastro() {
            // Simular cadastro
            setTimeout(() => {
                document.getElementById('step3').classList.add('hidden');
                document.getElementById('successMessage').classList.remove('hidden');
            }, 1500);
        }
    </script>
    
    <script async defer crossorigin="anonymous" src="https://connect.facebook.net/pt_BR/sdk.js"></script>
</body>
</html>