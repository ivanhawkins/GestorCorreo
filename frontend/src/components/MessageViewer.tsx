/**
 * MessageViewer component - displays full message with body and attachments
 */
import { useState, useEffect } from 'react'
import DOMPurify from 'dompurify'
import type { Message, MessageBody, Attachment } from '../services/apiExtended'
import { getMessageBody, getMessageAttachments, getAttachmentDownloadUrl } from '../services/apiExtended'
import { useMarkAsRead, useDeleteMessage } from '../hooks/useApi'
import { useToast } from '../hooks/useToast'
import './MessageViewer.css'

interface MessageViewerProps {
    message: Message
    onClose: () => void
    onReply?: (message: Message) => void
    onForward?: (message: Message) => void
}

export default function MessageViewer({ message, onClose, onReply, onForward }: MessageViewerProps) {
    const [body, setBody] = useState<MessageBody | null>(null)
    const [attachments, setAttachments] = useState<Attachment[]>([])
    const [loading, setLoading] = useState(true)
    const [showHtml, setShowHtml] = useState(true)

    const markAsRead = useMarkAsRead()
    const deleteMessage = useDeleteMessage()
    const { showSuccess, showError } = useToast()

    useEffect(() => {
        loadMessageContent()
        // Mark as read if not already read
        if (!message.is_read) {
            markAsRead.mutate({ messageId: message.id, isRead: true })
        }
    }, [message.id])

    const loadMessageContent = async () => {
        setLoading(true)
        try {
            const [bodyData, attachmentsData] = await Promise.all([
                getMessageBody(message.id),
                getMessageAttachments(message.id)
            ])
            setBody(bodyData)
            setAttachments(attachmentsData)
        } catch (error) {
            console.error('Error loading message content:', error)
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
            ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'u', 'a', 'img', 'table', 'tr', 'td', 'th', 'div', 'span', 'h1', 'h2', 'h3', 'ul', 'ol', 'li'],
            ALLOWED_ATTR: ['href', 'src', 'alt', 'style', 'class']
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
        if (onReply) {
            onReply(message)
            onClose()
        }
    }

    const handleForward = () => {
        if (onForward) {
            onForward(message)
            onClose()
        }
    }

    const downloadAttachment = (attachment: Attachment) => {
        const url = getAttachmentDownloadUrl(message.id, attachment.id)
        window.open(url, '_blank')
    }

    return (
        <div className="message-viewer-overlay" onClick={onClose}>
            <div className="message-viewer" onClick={(e) => e.stopPropagation()}>
                <div className="message-viewer-header">
                    <div className="message-header-info">
                        <h2>{message.subject || '(Sin asunto)'}</h2>
                        <div className="message-meta-info">
                            <div className="from-info">
                                <strong>De:</strong> {message.from_name || message.from_email}
                            </div>
                            <div className="date-info">
                                <strong>Fecha:</strong> {formatDate(message.date)}
                            </div>
                        </div>
                    </div>
                    <div className="header-actions">
                        <button className="btn-action" onClick={handleReply} title="Responder">
                            ↩️
                        </button>
                        <button className="btn-action" onClick={handleForward} title="Reenviar">
                            ➡️
                        </button>
                        <button
                            className="btn-action btn-danger"
                            onClick={(e) => {
                                e.stopPropagation();
                                handleDelete();
                            }}
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
                    ) : (
                        <>
                            {body?.body_html && body?.body_text && (
                                <div className="body-toggle">
                                    <button
                                        className={showHtml ? 'active' : ''}
                                        onClick={() => setShowHtml(true)}
                                    >
                                        HTML
                                    </button>
                                    <button
                                        className={!showHtml ? 'active' : ''}
                                        onClick={() => setShowHtml(false)}
                                    >
                                        Texto
                                    </button>
                                </div>
                            )}

                            <div className="message-content">
                                {showHtml && body?.body_html ? (
                                    <div
                                        className="html-content"
                                        dangerouslySetInnerHTML={{ __html: sanitizeHtml(body.body_html) }}
                                    />
                                ) : (
                                    <pre className="text-content">{body?.body_text || 'Sin contenido'}</pre>
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
