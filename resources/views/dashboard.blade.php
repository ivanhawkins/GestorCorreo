@extends('layouts.app')
@section('title', 'Hawkins Mail')

@section('content')
<div class="mail-app" id="app">

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo-row">
                <div class="sidebar-brand">
                    <div class="sidebar-brand-mark">H</div>
                    <div class="sidebar-brand-name">
                        Hawkins Mail
                        <span class="version">v.05</span>
                    </div>
                </div>
                <div style="display:flex; gap:0.25rem; align-items:center;">
                    <span id="ai-health-dot" title="Estado IA" style="width:10px;height:10px;border-radius:999px;background:#6b7280;display:inline-block"></span>
                    <button class="btn-icon" id="btn-settings" title="Ajustes de cuenta">⚙️</button>
                </div>
            </div>
            <div class="sidebar-user" id="sidebar-user"></div>
        </div>

        <div class="sidebar-actions">
            <button class="btn-sync" id="btn-sync">🔄 Sincronizar</button>
            <button class="btn-compose-sidebar" id="btn-compose-sidebar">✏️ Redactar</button>
        </div>

        <div id="sync-status" class="sync-status" style="display:none"></div>

        <div class="accounts-section">
            <div class="accounts-header">
                <h3>Cuentas</h3>
                <button class="btn-icon" id="btn-add-account" title="Añadir cuenta">&#43;</button>
            </div>
            <div id="accounts-list"></div>
        </div>

        <div class="folders-section">
            <div class="accounts-header">
                <h3>Carpetas</h3>
                <div style="display:flex; gap:.25rem;">
                    <button class="btn-icon" id="btn-add-folder" title="Crear carpeta personalizada">+</button>
                    <button class="btn-icon" id="btn-delete-folder" title="Eliminar carpeta seleccionada">🗑</button>
                </div>
            </div>
            <div id="folders-list">
                <div class="folder-item active" data-filter="all">
                    Bandeja de entrada <span class="total-count" id="count-all"></span>
                </div>
                <div class="folder-item" data-filter="Sent">Enviados <span class="total-count" id="count-Sent"></span></div>
                <div class="folder-item" data-filter="starred">Destacados <span class="total-count" id="count-starred"></span></div>
                <div class="folder-item" data-filter="Interesantes">Interesantes <span class="total-count" id="count-Interesantes"></span></div>
                <div class="folder-item" data-filter="Servicios">Servicios <span class="total-count" id="count-Servicios"></span></div>
                <div class="folder-item" data-filter="EnCopia">En copia <span class="total-count" id="count-EnCopia"></span></div>
                <div class="folder-item" data-filter="SPAM">SPAM <span class="total-count" id="count-SPAM"></span></div>
                <div class="folder-item" data-filter="deleted">Eliminados <span class="total-count" id="count-deleted"></span></div>
            </div>
        </div>

        <div class="sidebar-footer">
            <a href="/admin" id="btn-admin" class="btn-logout" style="display:none;text-decoration:none;text-align:left;margin-bottom:.4rem">Panel de administración</a>
            <button class="btn-logout" id="btn-logout">Cerrar sesión</button>
        </div>
    </aside>

    <!-- ── MAIN ── -->
    <div class="main-content">

        <div class="toolbar">
            <div class="toolbar-left">
                <input type="text" id="search-input" class="search-input" placeholder="Buscar mensajes…" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                <div class="form-row" style="margin-top:.4rem; gap:.4rem; align-items:center;">
                    <input type="date" id="filter-date-from" class="form-control" style="max-width:160px">
                    <input type="date" id="filter-date-to" class="form-control" style="max-width:160px">
                    <select id="filter-read" class="form-control" style="max-width:150px">
                        <option value="">Leídos y no leídos</option>
                        <option value="0">No leídos</option>
                        <option value="1">Leídos</option>
                    </select>
                    <button class="btn-toolbar" id="btn-clear-filters" type="button">Limpiar filtros</button>
                </div>
            </div>
            <div class="toolbar-actions">
                <button class="btn-toolbar" id="btn-mark-read">Marcar leídos</button>
                <button class="btn-toolbar" id="btn-empty-trash" style="display:none">Vaciar</button>
                <button class="btn-toolbar primary" id="btn-compose">Redactar</button>
            </div>
        </div>

        <div class="content-split-pane">
            <div class="list-pane" id="list-pane">
                <div id="messages-container"></div>
            </div>
            <div class="detail-pane" id="detail-pane" style="display:none">
                <div id="message-viewer"></div>
            </div>
        </div>

    </div>
</div>

<!-- ── MODAL: Compose ── -->
<div class="modal-overlay" id="modal-compose" style="display:none">
    <div class="modal-box compose-box">
        <div class="modal-header">
            <h3 id="compose-title">Nuevo mensaje</h3>
            <button class="btn-icon" id="btn-close-compose">&#10005;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Desde</label>
                <select id="compose-from" class="form-control"></select>
            </div>
            <div class="form-group">
                <label>Para</label>
                <input type="email" id="compose-to" class="form-control" placeholder="destinatario@dominio.com">
            </div>
            <div class="form-group">
                <label>CC</label>
                <input type="text" id="compose-cc" class="form-control" placeholder="cc@dominio.com">
            </div>
            <div class="form-group">
                <label>Asunto</label>
                <input type="text" id="compose-subject" class="form-control" placeholder="Asunto">
            </div>
            <div class="form-group">
                <label>Mensaje</label>
                <textarea id="compose-body" class="form-control compose-textarea" placeholder="Escribe tu mensaje…"></textarea>
            </div>
            <div class="form-group">
                <label>Instrucción para IA (tu estilo)</label>
                <input type="text" id="compose-ai-instruction" class="form-control" placeholder="Ej: Dile que sí, amable y breve">
            </div>
            <div class="form-group">
                <label>Adjuntar archivos</label>
                <input type="file" id="compose-files" class="form-control" multiple>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" id="btn-generate-compose-ai">Generar con IA</button>
            <button class="btn-secondary" id="btn-cancel-compose">Cancelar</button>
            <button class="btn-primary" id="btn-send">Enviar</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-message-large" style="display:none">
    <div class="modal-box" style="max-width:980px;width:96vw;">
        <div class="modal-header">
            <h3>Mensaje</h3>
            <button class="btn-icon" id="btn-close-message-large">&#10005;</button>
        </div>
        <div class="modal-body" id="message-large-content"></div>
    </div>
</div>

<!-- ── MODAL: Account ── -->
<div class="modal-overlay" id="modal-account" style="display:none">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="account-modal-title">Añadir cuenta</h3>
            <button class="btn-icon" id="btn-close-account">&#10005;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Nombre visible</label>
                <input type="text" id="acc-name" class="form-control" placeholder="Ej. Trabajo">
            </div>
            <div class="form-group">
                <label>Correo electrónico</label>
                <input type="email" id="acc-email" class="form-control" placeholder="usuario@dominio.com">
            </div>
            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" id="acc-password" class="form-control" placeholder="Se usa automáticamente la contraseña del registro" readonly disabled>
            </div>

            <hr class="form-divider">
            <p class="form-section-title">Recepción (IMAP / POP3)</p>
            <div class="form-row">
                <div class="form-group flex-2">
                    <label>Servidor</label>
                    <input type="text" id="acc-imap-host" class="form-control" placeholder="pop.ionos.es" value="pop.ionos.es">
                </div>
                <div class="form-group flex-1">
                    <label>Puerto</label>
                    <input type="number" id="acc-imap-port" class="form-control" value="995">
                </div>
                <div class="form-group flex-1">
                    <label>Cifrado</label>
                    <select id="acc-imap-ssl" class="form-control">
                        <option value="1">SSL/TLS</option>
                        <option value="0">Ninguno</option>
                    </select>
                </div>
            </div>

            <hr class="form-divider">
            <p class="form-section-title">Envío (SMTP)</p>
            <div class="form-row">
                <div class="form-group flex-2">
                    <label>Servidor</label>
                    <input type="text" id="acc-smtp-host" class="form-control" placeholder="smtp.ionos.es" value="smtp.ionos.es">
                </div>
                <div class="form-group flex-1">
                    <label>Puerto</label>
                    <input type="number" id="acc-smtp-port" class="form-control" value="465">
                </div>
                <div class="form-group flex-1">
                    <label>Cifrado</label>
                    <select id="acc-smtp-ssl" class="form-control">
                        <option value="1">SSL/TLS</option>
                        <option value="0">Ninguno</option>
                    </select>
                </div>
            </div>

            <hr class="form-divider">
            <p class="form-section-title">Personalización IA</p>
            <div class="form-group">
                <label>Tu perfil / forma de escribir</label>
                <textarea id="acc-owner-profile" class="form-control" rows="3" placeholder="Ej: Respuestas breves, cercanas, tono profesional, español de España."></textarea>
            </div>
            <div class="form-group">
                <label>Prompt de clasificación personalizado</label>
                <textarea id="acc-custom-classification-prompt" class="form-control" rows="3" placeholder="Instrucciones extra para clasificar correos de esta cuenta."></textarea>
            </div>
            <div class="form-group">
                <label>Firma HTML (se añade siempre al enviar)</label>
                <textarea id="acc-signature-html" class="form-control" rows="5" placeholder="<p>Un saludo,<br>Tu nombre</p>"></textarea>
            </div>
            <div class="form-group">
                <label>Tamaño de letra del panel</label>
                <select id="acc-font-size" class="form-control">
                    <option value="12">Pequeño</option>
                    <option value="13" selected>Normal</option>
                    <option value="14">Grande</option>
                    <option value="16">Muy grande</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" id="btn-cancel-account">Cancelar</button>
            <button class="btn-primary" id="btn-save-account">Guardar</button>
        </div>
    </div>
</div>

<!-- ── TOASTS ── -->
<div id="toast-container" class="toast-container"></div>

@push('scripts')
<script src="/js/mail.js"></script>
@endpush
@endsection
