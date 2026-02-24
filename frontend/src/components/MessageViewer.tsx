/**
 * MessageViewer component - displays full message with body and attachments
 * Uses ONLY getMessage() - no separate /body or /attachments calls needed.
 * The GET /api/messages/{id} endpoint already returns body_text, body_html and attachments.
 */
import { useState, useEffect } from 'react'
import DOMPurify from 'dompurify'
import type { Message, MessageDetail } from '../services/api'
import { getMessage } from '../services/api'
import { useMarkAsRead, useDeleteMessage } from '../hooks/useApi'
import { useToast } from '../hooks/useToast'
import './MessageViewer.css'

// Build attachment download URL using the same origin (no hardcoded host)
const getAttachmentDownloadUrl = (attachmentId: number): string => {
    return `/api/attachments/${attachmentId}`
}

interface MessageViewerProps {
    message: Message
    onClose: () => void
    onReply?: (message: Message) => void
    onReplyAll?: (message: Message | MessageDetail) => void
    onForward?: (message: Message) => void
}

export default function MessageViewer({ message, onClose, onReply, onReplyAll, onForward }: MessageViewerProps) {
    const [loading, setLoading] = useState(true)
    const [showHtml, setShowHtml] = useState(true)
    const [messageDetails, setMessageDetails] = useState<MessageDetail | null>(null)
    const [loadError, setLoadError] = useState<string | null>(null)

    const markAsRead = useMarkAsRead()
    const deleteMessage = useDeleteMessage()
    const { showSuccess, showError } = useToast()

    useEffect(() => {
        loadMessageContent()
        if (!message.is_read) {
            markAsRead.mutate({ messageId: message.id, isRead: true })
        }
    }, [message.id])

    const loadMessageContent = async () => {
        setLoading(true)
        setLoadError(null)
        try {
            // Single call — getMessage returns body_text, body_html AND attachments
            const details = await getMessage(message.id)
            setMessageDetails(details)
        } catch (error) {
            console.error('Error loading message content:', error)
            setLoadError('No se pudo cargar el contenido del mensaje.')
        } finally {
            setLoading(false)
        }
    }

    const formatDate = (dateString: string) => {
        const date = new Date(dateString)
        return date.toLocaleString('es-ES', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        })
    }

    const formatFileSize = (bytes: number): string => {
        if (bytes < 1024) return `${bytes} B`
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    }

    const sanitizeHtml = (html: string): string => {
        return DOMPurify.sanitize(html, {
            ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 'a', 'img', 'table', 'tr', 'td', 'th', 'div', 'span', 'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr', 'b', 'i'],
            ALLOWED_ATTR: ['href', 'src', 'alt', 'style', 'class', 'target', 'rel']
        })
    }

    const handleDelete = async () => {
        try {
            await deleteMessage.mutateAsync(message.id)
            showSuccess('Mensaje movido a Eliminados')
            onClose()
        } catch (error: any) {
            showError('Error al eliminar el mensaje')
        }
    }

    const handleReply = () => {
        if (onReply) { onReply(message); onClose() }
    }

    const handleReplyAll = () => {
        if (onReplyAll) { onReplyAll(messageDetails || message); onClose() }
    }

    const handleForward = () => {
        if (onForward) { onForward(message); onClose() }
    }

    const attachments = messageDetails?.attachments || []
    const bodyHtml = messageDetails?.body_html
    const bodyText = messageDetails?.body_text

    return (
        <div className="message-viewer-overlay" onClick={onClose}>
            <div className="message-viewer" onClick={(e) => e.stopPropagation()}>
                <div className="message-viewer-header">
                    <div className="message-header-info">
                        <h2>{message.subject || '(Sin asunto)'}</h2>
                        <div className="message-meta-info">
                            <div className="from-info">
                                <strong>De:</strong> {message.from_name} &lt;{message.from_email}&gt;
                            </div>
                            {messageDetails && (
                                <div className="to-info">
                                    <strong>Para:</strong> {(() => {
                                        try {
                                            const tos = JSON.parse(messageDetails.to_addresses || '[]');
                                            return Array.isArray(tos) ? tos.join(', ') : messageDetails.to_addresses;
                                        } catch (e) {
                                            return messageDetails.to_addresses;
                                        }
                                    })()}
                                </div>
                            )}
                            <div className="date-info">
                                <strong>Fecha:</strong> {formatDate(message.date)}
                            </div>
                        </div>
                    </div>
                    <div className="header-actions">
                        <button className="btn-action" onClick={handleReply} title="Responder">↩️</button>
                        <button className="btn-action" onClick={handleReplyAll} title="Responder a Todos">⏮️</button>
                        <button className="btn-action" onClick={handleForward} title="Reenviar">➡️</button>
                        <button
                            className="btn-action btn-danger"
                            onClick={(e) => { e.stopPropagation(); handleDelete(); }}
                            title="Eliminar"
                        >
                            🗑️
                        </button>
                        <button className="close-btn" onClick={onClose}>×</button>
                    </div>
                </div>

                <div className="message-viewer-body">
                    {loading ? (
                        <div className="loading-state">Cargando mensaje...</div>
                    ) : loadError ? (
                        <div className="error-state" style={{ padding: '1rem', color: '#c62828', background: '#ffebee', borderRadius: '4px' }}>
                            ⚠️ {loadError}
                            <button onClick={loadMessageContent} style={{ marginLeft: '1rem', cursor: 'pointer' }}>Reintentar</button>
                        </div>
                    ) : (
                        <>
                            {bodyHtml && bodyText && (
                                <div className="body-toggle">
                                    <button className={showHtml ? 'active' : ''} onClick={() => setShowHtml(true)}>HTML</button>
                                    <button className={!showHtml ? 'active' : ''} onClick={() => setShowHtml(false)}>Texto</button>
                                </div>
                            )}

                            <div className="message-content">
                                {showHtml && bodyHtml ? (
                                    <div
                                        className="html-content"
                                        dangerouslySetInnerHTML={{ __html: sanitizeHtml(bodyHtml) }}
                                    />
                                ) : (
                                    <pre className="text-content">{bodyText || 'Sin contenido'}</pre>
                                )}
                            </div>

                            {attachments.length > 0 && (
                                <div className="attachments-section">
                                    <h3>Adjuntos ({attachments.length})</h3>
                                    <div className="attachments-list">
                                        {attachments.map((attachment) => (
                                            <a
                                                key={attachment.id}
                                                href={getAttachmentDownloadUrl(attachment.id)}
                                                download={attachment.filename}
                                                className="attachment-item"
                                            >
                                                <span className="attachment-icon">📎</span>
                                                <div className="attachment-info">
                                                    <div className="attachment-name">{attachment.filename}</div>
                                                    <div className="attachment-size">{formatFileSize(attachment.size_bytes)}</div>
                                                </div>
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    )
}
