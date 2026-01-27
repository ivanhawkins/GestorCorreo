/**
 * AccountManager component - modal for adding email accounts
 */
import { useState } from 'react'
import { useCreateAccount } from '../hooks/useApi'
import { useToast } from '../hooks/useToast'
import type { AccountCreate } from '../services/api'
import './AccountManager.css'

interface AccountManagerProps {
    onClose: () => void
}

export default function AccountManager({ onClose }: AccountManagerProps) {
    const createAccount = useCreateAccount()
    const { showSuccess, showError } = useToast()

    const [formData, setFormData] = useState<AccountCreate>({
        email_address: 'ivan@hawkins.es',
        imap_host: 'imap.hawkins.es',
        imap_port: 993,
        smtp_host: 'smtp.hawkins.es',
        smtp_port: 587,
        username: 'ivan@hawkins.es',
        password: '',
    })

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()

        try {
            await createAccount.mutateAsync(formData)
            showSuccess('Account added successfully!')
            onClose()
        } catch (error: any) {
            const errorMsg = error?.response?.data?.detail || 'Failed to create account'
            showError(errorMsg)
            console.error('Error creating account:', error)
        }
    }

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const { name, value } = e.target
        setFormData(prev => ({
            ...prev,
            [name]: name.includes('port') ? parseInt(value) || 0 : value
        }))
    }

    return (
        <div className="modal-overlay" onClick={onClose}>
            <div className="modal-content" onClick={(e) => e.stopPropagation()}>
                <div className="modal-header">
                    <h2>Add Email Account</h2>
                    <button className="close-btn" onClick={onClose}>×</button>
                </div>

                <form onSubmit={handleSubmit} className="account-form">
                    <div className="form-group">
                        <label>Email Address</label>
                        <input
                            type="email"
                            name="email_address"
                            value={formData.email_address}
                            onChange={handleChange}
                            required
                            placeholder="your@email.com"
                        />
                    </div>

                    <div className="form-group">
                        <label>Username</label>
                        <input
                            type="text"
                            name="username"
                            value={formData.username}
                            onChange={handleChange}
                            required
                            placeholder="Usually same as email"
                        />
                    </div>

                    <div className="form-group">
                        <label>Password</label>
                        <input
                            type="password"
                            name="password"
                            value={formData.password}
                            onChange={handleChange}
                            required
                        />
                    </div>

                    <div className="form-row">
                        <div className="form-group">
                            <label>IMAP Host</label>
                            <input
                                type="text"
                                name="imap_host"
                                value={formData.imap_host}
                                onChange={handleChange}
                                required
                                placeholder="imap.gmail.com"
                            />
                        </div>
                        <div className="form-group">
                            <label>IMAP Port</label>
                            <input
                                type="number"
                                name="imap_port"
                                value={formData.imap_port}
                                onChange={handleChange}
                                required
                            />
                        </div>
                    </div>

                    <div className="form-row">
                        <div className="form-group">
                            <label>SMTP Host</label>
                            <input
                                type="text"
                                name="smtp_host"
                                value={formData.smtp_host}
                                onChange={handleChange}
                                required
                                placeholder="smtp.gmail.com"
                            />
                        </div>
                        <div className="form-group">
                            <label>SMTP Port</label>
                            <input
                                type="number"
                                name="smtp_port"
                                value={formData.smtp_port}
                                onChange={handleChange}
                                required
                            />
                        </div>
                    </div>

                    <div className="form-actions">
                        <button type="button" onClick={onClose} className="btn-secondary">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className="btn-primary"
                            disabled={createAccount.isPending}
                        >
                            {createAccount.isPending ? 'Adding...' : 'Add Account'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
