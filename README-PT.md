# CopartRemocoes

## Sobre Este Projeto

CopartRemocoes é uma aplicação web **atualmente em uso operacional**, projetada para gerenciar e rastrear eficientemente o processo de remoção de veículos sinistrados (perda total) para um fluxo de trabalho interno de uma empresa. Ela aborda o desafio real de coordenar ordens de seguradoras com a logística de transporte.

Quando uma seguradora declara um veículo como perda total, ela emite uma ordem em PDF contendo detalhes como endereços, modelo do veículo, placa e ID. Esta aplicação otimiza o processo de registro permitindo que os usuários façam o upload deste PDF; um serviço de backend então lê automaticamente as informações relevantes e pré-preenche os campos do formulário, reduzindo significativamente a entrada manual de dados e potenciais erros.

Construída com **Laravel** e **Filament PHP**, o sistema proporciona controle aprimorado sobre as operações de remoção e oferece flexibilidade para que os funcionários atualizem o status do veículo remotamente. Cada usuário possui um login único, garantindo que todas as alterações e criações sejam registradas para responsabilidade. Os documentos PDF carregados são armazenados de forma segura usando **AWS S3**.

Este projeto demonstra a aplicação prática de tecnologias modernas de desenvolvimento web (Laravel, Filament), integração com serviços externos (AWS S3) e automação de processos (extração de dados de PDF) para resolver uma necessidade de negócio específica.

## Funcionalidades

* **Rastreamento de Remoção de Veículos:** Gerencie e monitore o status das remoções de veículos.
* **Entrada de Dados Automatizada:** Extrai dados (endereço, modelo, placa, ID, etc.) diretamente das ordens de remoção em PDF carregadas para preencher automaticamente os formulários de registro.
* **Armazenamento em Nuvem:** Armazena de forma segura os documentos PDF carregados associados a cada registro de remoção usando AWS S3.
* **Painel de Administração Interno:** Interface amigável construída com Filament.
* **Auditoria de Usuário:** Logins específicos por usuário para rastrear ações e manter logs.
* **Acessibilidade Remota:** Projetado para flexibilidade, permitindo atualizações de qualquer lugar.
* **Stack de Tecnologia:** Construído com Laravel, Filament PHP e integra-se com AWS S3.

## Instruções de Instalação

**Nota:** Estas são instruções de instalação padrão do Laravel. Pode ser necessário ajustá-las com base nos requisitos específicos deste projeto (por exemplo, versão específica do PHP, outras extensões necessárias, configuração do SDK da AWS, configurações de banco de dados encontradas em `.env.example`).

**1. Pré-requisitos:**

* **PHP 8.0^:** Certifique-se de ter uma versão compatível do PHP instalada.
* **Composer:** [Instalar o Composer](https://getcomposer.org/download/)
* **Banco de Dados:** Um servidor de banco de dados relacional compatível (ex: MySQL, PostgreSQL).
* **Utilitários Poppler:** Necessário para a funcionalidade de extração de texto de PDF. Instale para o seu sistema operacional:
    * **Linux (Debian/Ubuntu):**
        ```bash
        sudo apt update && sudo apt install poppler-utils
        ```
    * **Linux (Fedora/CentOS/RHEL):**
        ```bash
        sudo yum update && sudo yum install poppler-utils
        # Ou usando dnf
        # sudo dnf install poppler-utils
        ```
    * **macOS (usando Homebrew):**
        ```bash
        brew install poppler
        ```
    * **Windows:**
        * **Usando Chocolatey (Recomendado):** Abra o PowerShell como Administrador e execute:
            ```powershell
            choco install poppler
            ```
          *(Pode ser necessário instalar o Chocolatey primeiro: [https://chocolatey.org/install](https://chocolatey.org/install))*
        * **Download Manual:** Você pode encontrar binários para Windows, mas as fontes variam. Procure por "poppler windows binaries". Certifique-se de que o diretório de instalação contendo `pdftotext.exe` seja adicionado à variável de ambiente PATH do seu sistema.
        * **Usando WSL (Subsistema Windows para Linux):** Instale através da distribuição Linux rodando no WSL usando os comandos Linux acima.

**2. Clonar o repositório:**
```bash
git clone https://github.com/lanng/CopartRemocoes.git
cd CopartRemocoes
```

**3. Instalar Dependências PHP:**
```bash
composer install
```

**5. Configuração do Ambiente:**
Copie o arquivo de ambiente de exemplo e configure-o.
```bash
cp .env.example .env
```
* Abra o `.env` e atualize `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, etc.
* Configure as credenciais do AWS S3 (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`).
* Certifique-se que `FILESYSTEM_DISK` está definido como `s3` (ou o disco configurado para PDFs).
* Ou altere o `FILESYSTEM_DISK` para ser armazenado dentro da propria aplicação para testes.
* Gere a chave da aplicação:
```bash
php artisan key:generate
```

**6. Migrações e Seeds do Banco de Dados:**
```bash
php artisan migrate
```

**7. Servir a Aplicação:**
```bash
php artisan serve
```
Acesse a aplicação, geralmente em `http://127.0.0.1:8000`.
## Implantação (Deployment)

Esta aplicação está atualmente implantada em um VPS. Os passos de implantação podem variar dependendo do ambiente do servidor (por exemplo, usando Nginx/Apache, Supervisor para filas, garantindo que as bibliotecas de processamento de PDF necessárias estejam instaladas no servidor, configurando credenciais da AWS de forma segura). Consulte a documentação de implantação do Laravel para orientações gerais.
