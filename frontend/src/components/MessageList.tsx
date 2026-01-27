/**
 * MessageList component - displays list of email messages
 */
import { useState } from 'react'
import { useClassifyMessage, useToggleStar } from '../hooks/useApi'
import { useToast } from '../hooks/useToast'
import type { Message } from '../services/api'
import './MessageList.css'

interface MessageListProps {
    messages: Message[]
    onMessageClick?: (message: Message) => void
}

export default function MessageList({ messages, onMessageClick }: MessageListProps) {
    const [classifyingId, setClassifyingId] = useState<string | null>(null)
    const classifyMessage = useClassifyMessage()
    const toggleStar = useToggleStar()
    const { showSuccess, showError } = useToast()

    if (messages.length === 0) {
        return (
            <div className="empty-state">
                <p>No messages found</p>
                <p className="hint">Click "Sync" to fetch new messages</p>
            </div>
        )
    }

    const formatDate = (dateString: string) => {
        const date = new Date(dateString)
        const now = new Date()
        const diffMs = now.getTime() - date.getTime()
        const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

        if (diffDays === 0) {
            return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
        } else if (diffDays === 1) {
            return 'Yesterday'
        } else if (diffDays < 7) {
            return date.toLocaleDateString('en-US', { weekday: 'short' })
        } else {
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
        }
    }

    const getCategoryBadge = (label?: string) => {
        if (!label) return null

        const badges = {
            'Interesantes': { icon: '⭐', className: 'badge-interesantes' },
            'SPAM': { icon: '🚫', className: 'badge-spam' },
            'EnCopia': { icon: '📋', className: 'badge-encopia' },
            'Servicios': { icon: '🔔', className: 'badge-servicios' }
        }

        const badge = badges[label as keyof typeof badges]
        if (!badge) return null

        return (
            <span className={`classification-badge ${badge.className}`}>
                {badge.icon} {label}
            </span>
        )
    }

    const handleClassify = async (e: React.MouseEvent, messageId: string) => {
        e.stopPropagation()
        setClassifyingId(messageId)

        try {
            const result = await classifyMessage.mutateAsync(messageId)
            showSuccess(`Classified as: ${result.classification.final_label}`)
        } catch (error: any) {
            showError(error?.response?.data?.detail || 'Failed to classify message')
        } finally {
            setClassifyingId(null)
        }
    }

    const handleToggleStar = async (e: React.MouseEvent, messageId: string, currentState: boolean) => {
        e.stopPropagation()
        try {
            await toggleStar.mutateAsync({ messageId, isStarred: !currentState })
        } catch (error: any) {
            showError('Failed to toggle star')
        }
    }



    return (
        <div className="message-list">
            {messages.map((message) => (
                <div
                    key={message.id}
                    className={`message-item ${message.is_read ? 'read' : 'unread'}`}
                    onClick={() => onMessageClick?.(message)}
                    draggable
                    onDragStart={(e) => {
                        e.dataTransfer.setData('text/plain', message.id)
                        e.dataTransfer.effectAllowed = 'move'
                    }}
                >
                    <div className="message-from">
                        {message.from_name || message.from_email}
                        {getCategoryBadge(message.classification_label)}
                    </div>
                    <div className="message-subject">
                        {message.subject || '(No subject)'}
                    </div>
                    <div className="message-snippet">
                        {message.snippet}
                    </div>
                    <div className="message-meta">
                        <span className="message-date">{formatDate(message.date)}</span>
                        {message.has_attachments && <span className="attachment-icon">📎</span>}
                        <button
                            className={`btn-star ${message.is_starred ? 'starred' : ''}`}
                            onClick={(e) => handleToggleStar(e, message.id, message.is_starred)}
                            title={message.is_starred ? 'Unstar' : 'Star'}
                        >
                            {message.is_starred ? '⭐' : '☆'}
                        </button>
                        {!message.classification_label && (
                            <button
                                className="btn-classify"
                                onClick={(e) => handleClassify(e, message.id)}
                                disabled={classifyingId === message.id}
                            >
                                {classifyingId === message.id ? '⏳' : '🤖 Classify'}
                            </button>
                        )}
                    </div>
                </div>
            ))}
        </div>
    )
}
