/**
 * Email Composer component with Reply/Forward support and attachments
 */
import { useState, useEffect, type ChangeEvent } from 'react'
import { useAccounts } from '../hooks/useApi'
import { useToast } from '../hooks/useToast'
import type { Message } from '../services/api'
import axios from 'axios'
import './Composer.css'

type ComposerMode = 'new' | 'reply' | 'forward'

interface Attachment {
    filename: string
    content: string // base64
    content_type: string
}

interface ComposerProps {
    onClose: () => void
    mode?: ComposerMode
    originalMessage?: Message
}

export default function Composer({ onClose, mode = 'new', originalMessage }: ComposerProps) {
    const { data: accounts } = useAccounts()
    const { showSuccess, showError, showInfo } = useToast()

    const [accountId, setAccountId] = useState<number>(accounts?.[0]?.id || 0)
    const [to, setTo] = useState('')
    const [cc, setCc] = useState('')
    const [bcc, setBcc] = useState('')
    const [subject, setSubject] = useState('')
    const [body, setBody] = useState('')
    const [attachments, setAttachments] = useState<Attachment[]>([])
    const [sending, setSending] = useState(false)

    // Pre-fill fields based on mode
    useEffect(() => {
        if (!originalMessage) return

        if (mode === 'reply') {
            setTo(originalMessage.from_email)
            setSubject(originalMessage.subject?.startsWith('Re:')
                ? originalMessage.subject
                : `Re: ${originalMessage.subject || ''}`)
            setBody(`\n\n--- Original Message ---\nFrom: ${originalMessage.from_name || originalMessage.from_email}\nDate: ${new Date(originalMessage.date).toLocaleString()}\nSubject: ${originalMessage.subject}\n\n`)
        } else if (mode === 'forward') {
            setSubject(originalMessage.subject?.startsWith('Fwd:')
                ? originalMessage.subject
                : `Fwd: ${originalMessage.subject || ''}`)
            setBody(`\n\n--- Forwarded Message ---\nFrom: ${originalMessage.from_name || originalMessage.from_email}\nDate: ${new Date(originalMessage.date).toLocaleString()}\nSubject: ${originalMessage.subject}\n\n`)
        }
    }, [mode, originalMessage])

    const handleFileSelect = (e: ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files || [])

        files.forEach(file => {
            // Limit file size to 10MB
            if (file.size > 10 * 1024 * 1024) {
                showError(`File ${file.name} is too large (max 10MB)`)
                return
            }

            const reader = new FileReader()
            reader.onload = () => {
                const base64 = (reader.result as string).split(',')[1]
                setAttachments(prev => [...prev, {
                    filename: file.name,
                    content: base64,
                    content_type: file.type || 'application/octet-stream'
                }])
            }
            reader.onerror = () => {
                showError(`Failed to read file ${file.name}`)
            }
            reader.readAsDataURL(file)
        })
    }

    const removeAttachment = (index: number) => {
        setAttachments(prev => prev.filter((_, i) => i !== index))
    }

    const [generating, setGenerating] = useState(false)

    const handleGenerateAI = async () => {
        if (!originalMessage) return

        setGenerating(true)
        showInfo('Generating AI response...')

        try {
            // Find current account to get the profile
            const currentAccount = accounts?.find(a => a.id === accountId);
            const ownerProfile = currentAccount?.owner_profile || "Eres un asistente profesional y educado.";

            const response = await axios.post('http://localhost:8000/api/ai/generate_reply', {
                original_from_name: originalMessage.from_name,
                original_from_email: originalMessage.from_email,
                original_subject: originalMessage.subject,
                original_body: originalMessage.snippet || "No content",
                user_instruction: "Genera una respuesta profesional.",
                owner_profile: ownerProfile
            })

            if (response.data.reply_body) {
                // Append to current body
                setBody(prev => prev + '\n' + response.data.reply_body)
                showSuccess('AI Reply Generated!')
            }
        } catch (error) {
            console.error(error)
            showError('Failed to generate AI reply')
        } finally {
            setGenerating(false)
        }
    }

    const handleSend = async (e: React.FormEvent) => {
        e.preventDefault()

        if (!to.trim()) {
            showError('Please enter at least one recipient')
            return
        }

        setSending(true)

        try {
            const toList = to.split(',').map(email => email.trim()).filter(Boolean)
            const ccList = cc ? cc.split(',').map(email => email.trim()).filter(Boolean) : undefined
            const bccList = bcc ? bcc.split(',').map(email => email.trim()).filter(Boolean) : undefined

            await axios.post('http://localhost:8000/api/send', {
                account_id: accountId,
                to: toList,
                cc: ccList,
                bcc: bccList,
                subject,
                body_text: body,
                attachments: attachments.length > 0 ? attachments : undefined
            })

            showSuccess('Email sent successfully!')
            onClose()
        } catch (error: any) {
            showError(error?.response?.data?.detail || 'Failed to send email')
        } finally {
            setSending(false)
        }
    }

    const getTitle = () => {
        switch (mode) {
            case 'reply': return '↩️ Reply'
            case 'forward': return '➡️ Forward'
            default: return '✉️ Compose Email'
        }
    }

    return (
        <div className="composer-overlay" onClick={onClose}>
            <div className="composer" onClick={(e) => e.stopPropagation()}>
                <div className="composer-header">
                    <h2>{getTitle()}</h2>
                    <button className="close-btn" onClick={onClose}>×</button>
                </div>

                <form onSubmit={handleSend} className="composer-form">
                    <div className="form-group">
                        <label>From Account</label>
                        <select
                            value={accountId}
                            onChange={(e) => setAccountId(Number(e.target.value))}
                            required
                        >
                            {accounts?.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.email_address}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="form-group">
                        <label>To</label>
                        <input
                            type="text"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                            placeholder="recipient@example.com, another@example.com"
                            required
                        />
                    </div>

                    <div className="form-group">
                        <label>Cc (optional)</label>
                        <input
                            type="text"
                            value={cc}
                            onChange={(e) => setCc(e.target.value)}
                            placeholder="cc@example.com"
                        />
                    </div>

                    <div className="form-group">
                        <label>Bcc (optional)</label>
                        <input
                            type="text"
                            value={bcc}
                            onChange={(e) => setBcc(e.target.value)}
                            placeholder="bcc@example.com"
                        />
                    </div>

                    <div className="form-group">
                        <label>Subject</label>
                        <input
                            type="text"
                            value={subject}
                            onChange={(e) => setSubject(e.target.value)}
                            placeholder="Email subject"
                            required
                        />
                    </div>

                    <div className="form-group">
                        <label>Message</label>
                        <textarea
                            value={body}
                            onChange={(e) => setBody(e.target.value)}
                            placeholder="Write your message here..."
                            rows={12}
                            required
                        />
                    </div>

                    <div className="form-group">
                        <label>Attachments</label>
                        <input
                            type="file"
                            onChange={handleFileSelect}
                            multiple
                            className="file-input"
                        />
                        {attachments.length > 0 && (
                            <div className="attachments-list">
                                {attachments.map((att, index) => (
                                    <div key={index} className="attachment-item">
                                        <span className="attachment-name">📎 {att.filename}</span>
                                        <button
                                            type="button"
                                            onClick={() => removeAttachment(index)}
                                            className="btn-remove"
                                        >
                                            ×
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="composer-actions">
                        {/* AI Generator Button */}
                        {(mode === 'reply' && originalMessage) && (
                            <button
                                type="button"
                                onClick={handleGenerateAI}
                                className="btn-ai-generate"
                                disabled={generating}
                                title="Generate with AI"
                                style={{ marginRight: 'auto' }}
                            >
                                {generating ? '🎲 Generating...' : '🎲 AI Reply'}
                            </button>
                        )}

                        <button type="button" onClick={onClose} className="btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" className="btn-primary" disabled={sending}>
                            {sending ? '📤 Sending...' : '📨 Send'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
