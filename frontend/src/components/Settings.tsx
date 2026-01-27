/**
 * Settings component - manage accounts and test connections
 */
import { useState } from 'react'
import { useAccounts, useCategories, useCreateCategory, useUpdateCategory, useDeleteCategory } from '../hooks/useApi'
import { useToast } from '../hooks/useToast'
import axios from 'axios'
import './Settings.css'

interface SettingsProps {
    onClose: () => void
}

function ProfileEditor({ account, onSave }: { account: any, onSave: () => void }) {
    const [profile, setProfile] = useState(account.owner_profile || '')
    const [saving, setSaving] = useState(false)
    const { showSuccess, showError } = useToast()

    const handleSave = async () => {
        setSaving(true)
        try {
            await axios.put(`http://localhost:8000/api/accounts/${account.id}`, { owner_profile: profile })
            showSuccess('Profile updated')
            onSave()
        } catch (err) {
            showError('Failed to update profile')
        } finally {
            setSaving(false)
        }
    }

    return (
        <div className="profile-editor" style={{ marginTop: '5px', width: '100%' }}>
            <textarea
                cols={40}
                rows={3}
                value={profile}
                onChange={(e) => setProfile(e.target.value)}
                placeholder="Example: I am Ivan, CEO of Hawkins. I am direct but polite..."
                style={{ width: '100%', padding: '5px', borderRadius: '4px', border: '1px solid #ccc' }}
            />
            <button
                className="btn-save-profile"
                style={{ marginTop: '5px', padding: '4px 8px', cursor: 'pointer' }}
                onClick={handleSave}
                disabled={saving}
            >
                {saving ? '💾 Saving...' : '💾 Save Profile'}
            </button>
        </div>
    )
}

export default function Settings({ onClose }: SettingsProps) {
    const { data: accounts, refetch } = useAccounts()
    const { showSuccess, showError, showInfo } = useToast()
    const [testingId, setTestingId] = useState<number | null>(null)

    const handleTestConnection = async (accountId: number) => {
        setTestingId(accountId)
        showInfo('Testing connection...')

        try {
            await axios.post(`http://localhost:8000/api/accounts/${accountId}/test`)
            showSuccess('Connection successful!')
        } catch (error: any) {
            showError(error?.response?.data?.detail || 'Connection failed')
        } finally {
            setTestingId(null)
        }
    }

    const handleDeleteAccount = async (accountId: number, email: string) => {
        if (!confirm(`Delete account ${email}?`)) return

        try {
            await axios.delete(`http://localhost:8000/api/accounts/${accountId}`)
            showSuccess('Account deleted')
            refetch()
        } catch (error: any) {
            showError(error?.response?.data?.detail || 'Failed to delete account')
        }
    }

    return (
        <div className="settings-overlay" onClick={onClose}>
            <div className="settings" onClick={(e) => e.stopPropagation()}>
                <div className="settings-header">
                    <h2>⚙️ Settings</h2>
                    <button className="close-btn" onClick={onClose}>×</button>
                </div>

                <div className="settings-content">
                    <section className="settings-section">
                        <h3>Email Accounts</h3>

                        {!accounts || accounts.length === 0 ? (
                            <p className="empty-message">No accounts configured</p>
                        ) : (
                            <div className="accounts-list">
                                {accounts.map((account) => (
                                    <div key={account.id} className="account-card">
                                        <div className="account-info">
                                            <div className="account-email">
                                                <strong>{account.email_address}</strong>
                                                <span className={`status-badge ${account.is_active ? 'active' : 'inactive'}`}>
                                                    {account.is_active ? '✓ Active' : '✕ Inactive'}
                                                </span>
                                            </div>
                                            <div className="account-details">
                                                <div className="detail-row">
                                                    <span className="label">IMAP:</span>
                                                    <span>{account.imap_host}:{account.imap_port}</span>
                                                </div>
                                                <div className="detail-row">
                                                    <span className="label">Owner Profile (AI Persona):</span>
                                                    <ProfileEditor account={account} onSave={refetch} />
                                                </div>
                                            </div>
                                        </div>

                                        <div className="account-actions">
                                            <button
                                                className="btn-test"
                                                onClick={() => handleTestConnection(account.id)}
                                                disabled={testingId === account.id}
                                            >
                                                {testingId === account.id ? '⏳ Testing...' : '🔌 Test Connection'}
                                            </button>
                                            <button
                                                className="btn-delete"
                                                onClick={() => handleDeleteAccount(account.id, account.email_address)}
                                            >
                                                🗑️ Delete
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <section className="settings-section">
                        <h3>AI Classification Settings</h3>

                        <div className="ai-config">
                            <div className="config-group">
                                <label className="config-label">Email Categories & Instructions</label>
                                <p className="config-description">
                                    Define the categories used for email classification and their AI rules.
                                </p>
                                <CategoryManager />
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    )
}

function CategoryManager() {
    const { data: categories, isLoading } = useCategories()
    const createCategory = useCreateCategory()
    const updateCategory = useUpdateCategory()
    const deleteCategory = useDeleteCategory()
    const { showSuccess, showError } = useToast()

    const [editingId, setEditingId] = useState<number | null>(null)
    const [editForm, setEditForm] = useState<any>({})
    const [isAdding, setIsAdding] = useState(false)
    const [newCategory, setNewCategory] = useState({ key: '', name: '', description: '', ai_instruction: '', icon: '📁' })

    const handleEdit = (category: any) => {
        setEditingId(category.id)
        setEditForm({ ...category })
    }

    const handleCancelEdit = () => {
        setEditingId(null)
        setEditForm({})
    }

    const handleSaveEdit = async () => {
        try {
            await updateCategory.mutateAsync({ id: editingId!, data: editForm })
            showSuccess('Category updated')
            setEditingId(null)
        } catch (err) {
            showError('Failed to update category')
        }
    }

    const handleDelete = async (id: number) => {
        if (!confirm('Delete this category?')) return
        try {
            await deleteCategory.mutateAsync(id)
            showSuccess('Category deleted')
        } catch (err) {
            showError('Failed to delete category')
        }
    }

    const handleAdd = async () => {
        if (!newCategory.key || !newCategory.ai_instruction) {
            showError('Key and Instruction are required')
            return
        }
        try {
            await createCategory.mutateAsync(newCategory)
            showSuccess('Category created')
            setIsAdding(false)
            setNewCategory({ key: '', name: '', description: '', ai_instruction: '', icon: '📁' })
        } catch (err: any) {
            showError(err?.response?.data?.detail || 'Failed to create category')
        }
    }

    if (isLoading) return <div>Loading categories...</div>

    return (
        <div className="category-manager">
            <div className="categories-list">
                {categories?.map((cat: any) => (
                    <div key={cat.id} className="category-item-editable" style={{ background: 'rgba(255,255,255,0.05)', padding: '10px', borderRadius: '8px', marginBottom: '10px', border: '1px solid rgba(255,255,255,0.1)' }}>
                        {editingId === cat.id ? (
                            <div className="edit-form" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                                <div style={{ display: 'flex', gap: '10px' }}>
                                    <input value={editForm.name} onChange={e => setEditForm({ ...editForm, name: e.target.value })} placeholder="Display Name" style={{ flex: 1 }} />
                                    <input value={editForm.icon} onChange={e => setEditForm({ ...editForm, icon: e.target.value })} placeholder="Icon" style={{ width: '50px' }} />
                                </div>
                                <input value={editForm.description} onChange={e => setEditForm({ ...editForm, description: e.target.value })} placeholder="Description" />
                                <textarea value={editForm.ai_instruction} onChange={e => setEditForm({ ...editForm, ai_instruction: e.target.value })} placeholder="AI Instruction" rows={3} style={{ width: '100%' }} />
                                <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                                    <button onClick={handleSaveEdit} className="btn-primary">Save</button>
                                    <button onClick={handleCancelEdit} className="btn-secondary">Cancel</button>
                                </div>
                            </div>
                        ) : (
                            <div className="view-mode" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                <div style={{ flex: 1 }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                                        <span style={{ fontSize: '1.2em' }}>{cat.icon}</span>
                                        <strong>{cat.name}</strong>
                                        <code style={{ fontSize: '0.8em', background: 'rgba(255,255,255,0.1)', padding: '2px 4px', borderRadius: '4px' }}>{cat.key}</code>
                                    </div>
                                    <p style={{ margin: '4px 0', fontSize: '0.9em', color: 'rgba(255,255,255,0.7)' }}>{cat.description}</p>
                                    <p style={{ margin: '4px 0', fontSize: '0.85em', color: '#a5b4fc', fontStyle: 'italic' }}>Rule: {cat.ai_instruction.substring(0, 80)}...</p>
                                </div>
                                <div style={{ display: 'flex', gap: '8px' }}>
                                    <button onClick={() => handleEdit(cat)} className="btn-secondary" style={{ padding: '4px 8px' }}>✏️</button>
                                    {!cat.is_system && (
                                        <button onClick={() => handleDelete(cat.id)} className="btn-delete" style={{ padding: '4px 8px' }}>🗑️</button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                ))}
            </div>

            {isAdding ? (
                <div className="add-form" style={{ marginTop: '20px', padding: '15px', background: 'rgba(59, 130, 246, 0.1)', borderRadius: '8px', border: '1px solid rgba(59, 130, 246, 0.3)' }}>
                    <h4>New Category</h4>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                        <div style={{ display: 'flex', gap: '10px' }}>
                            <input
                                value={newCategory.key}
                                onChange={e => setNewCategory({ ...newCategory, key: e.target.value.replace(/\s+/g, '') })}
                                placeholder="Key (e.g. Proveedores)"
                                style={{ flex: 1 }}
                            />
                            <input value={newCategory.name} onChange={e => setNewCategory({ ...newCategory, name: e.target.value })} placeholder="Display Name" style={{ flex: 1 }} />
                            <input value={newCategory.icon} onChange={e => setNewCategory({ ...newCategory, icon: e.target.value })} placeholder="Icon" style={{ width: '50px' }} />
                        </div>
                        <input value={newCategory.description} onChange={e => setNewCategory({ ...newCategory, description: e.target.value })} placeholder="Description" />
                        <textarea
                            value={newCategory.ai_instruction}
                            onChange={e => setNewCategory({ ...newCategory, ai_instruction: e.target.value })}
                            placeholder="AI Instruction: Describe what emails belong here..."
                            rows={3}
                            style={{ width: '100%' }}
                        />
                        <div style={{ display: 'flex', gap: '10px', justifyContent: 'flex-end' }}>
                            <button onClick={handleAdd} className="btn-primary">Create Category</button>
                            <button onClick={() => setIsAdding(false)} className="btn-secondary">Cancel</button>
                        </div>
                    </div>
                </div>
            ) : (
                <button onClick={() => setIsAdding(true)} className="btn-primary" style={{ marginTop: '20px', width: '100%' }}>
                    + Add Custom Category
                </button>
            )}
        </div>
    )
}
