# BTZ.IO Chatbot Backend

## Setup em 5 minutos

### 1. Variáveis de ambiente (ou edite config/database.php)
```
DB_HOST=db.SEU_ID.supabase.co
DB_PORT=5432
DB_NAME=postgres
DB_USER=postgres
DB_PASS=SUA_SENHA
ALLOWED_ORIGIN=https://seusite.com
```

### 2. Subir localmente (PHP built-in server)
```bash
cd chatbot-backend
php -S localhost:8000
```
Acesse: http://localhost:8000/admin/index.html

### 3. Deploy em produção (Apache/Nginx)
- Faça upload de todos os arquivos para o servidor
- Garanta que mod_rewrite está ativo (Apache)
- Configure as variáveis de ambiente ou edite config/database.php

### 4. Nginx (alternativa ao .htaccess)
```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## Endpoints da API

| Método | Rota                          | Descrição                    |
|--------|-------------------------------|------------------------------|
| GET    | /api/flows                    | Lista todos os fluxos        |
| POST   | /api/flows                    | Cria novo fluxo              |
| GET    | /api/flows/:id                | Busca fluxo completo         |
| PUT    | /api/flows/:id                | Atualiza nome/descrição      |
| DELETE | /api/flows/:id                | Remove fluxo                 |
| POST   | /api/flows/:id/nodes          | Salva nós + opções + edges   |
| GET    | /api/flows/:id/nodes          | Lista nós do fluxo           |
| GET    | /api/flows/:id/export         | Gera e baixa plugin ZIP      |

## Como usar o Admin

1. Abra `/admin/index.html`
2. Clique em **Novo Fluxo**
3. Abra o fluxo → clique em **Adicionar nó**
4. Configure tipo, conteúdo e conexões de cada nó
5. Clique em **Salvar**
6. Clique em **Exportar ZIP** → instale no WordPress

## Instalação do Plugin WordPress

1. WP Admin → Plugins → Enviar plugin → upload do ZIP
2. Ativar o plugin
3. Em qualquer página/post, adicione o shortcode `[chatbot]`
