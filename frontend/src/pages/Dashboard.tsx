import { useState, useEffect } from 'react'
import '../App.css'
import { useAccounts, useMessages, useBulkMarkAsRead, useCategories, streamSync, useEmptyFolder, useDeleteAccount, useRestoreAccount, useToggleStar, useUpdateClassification, useDeleteMessage } from '../hooks/useApi'
import { useQueryClient } from '@tanstack/react-query'
import type { Message } from '../services/api'
import axios from 'axios'
import AccountManager from '../components/AccountManager'
import MessageList from '../components/MessageList'
import MessageViewer from '../components/MessageViewer'
import Composer from '../components/Composer'
import Settings from '../components/Settings'
import SearchBar from '../components/SearchBar'
import SyncStatus from '../components/SyncStatus'
import Toast from '../components/Toast'
import { useToast } from '../hooks/useToast'
import { useAuth } from '../context/AuthContext'
import { useNavigate } from 'react-router-dom'

type CategoryFilter = 'all' | 'starred' | 'deleted' | string

const Dashboard: React.FC = () => {
    console.log('Dashboard component rendering...');
    const { user, logout } = useAuth();
    const navigate = useNavigate();
    const queryClient = useQueryClient()
    const [selectedAccount, setSelectedAccount] = useState<number | null>(null)
    const [showAccountManager, setShowAccountManager] = useState(false)
    const [selectedMessage, setSelectedMessage] = useState<Message | null>(null)
    const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all')
    const [showComposer, setShowComposer] = useState(false)

    const [showSettings, setShowSettings] = useState(false)
    const [bulkClassifying, setBulkClassifying] = useState(false)
    const [composerMode, setComposerMode] = useState<'new' | 'reply' | 'forward'>('new')
    const [composerOriginalMessage, setComposerOriginalMessage] = useState<Message | null>(null)
    const [searchFilters, setSearchFilters] = useState<any>({})


    // Dual sync state
    const [syncState, setSyncState] = useState<{
        download: { status: 'pending' | 'active' | 'complete' | 'error'; current: number; total: number; message?: string };
        classify: { status: 'pending' | 'active' | 'complete' | 'error'; current: number; total: number; message?: string };
    } | null>(null)

    const { toasts, removeToast, showSuccess, showError, showInfo } = useToast()

    // Accounts queries
    const { data: accounts, isLoading: accountsLoading } = useAccounts(false)
    const { data: deletedAccounts } = useAccounts(true)
    const deleteAccount = useDeleteAccount()
    const restoreAccount = useRestoreAccount()

    // Handlers for account deletion/restoration
    const handleDeleteAccount = async (id: number) => {
        if (confirm('Are you sure you want to delete this account?')) {
            try {
                await deleteAccount.mutateAsync({ id, permanent: false })
                showSuccess('Cuenta movida a la papelera')
                if (selectedAccount === id) setSelectedAccount(null)
            } catch (e) {
                showError('Error al eliminar cuenta')
            }
        }
    }

    const handleRestoreAccount = async (id: number) => {
        try {
            await restoreAccount.mutateAsync(id)
            showSuccess('Cuenta restaurada')
        } catch (e) {
            showError('Error al restaurar cuenta')
        }
    }

    const handlePermanentDelete = async (id: number) => {
        if (confirm('Are you sure you want to PERMANENTLY delete this account? This cannot be undone.')) {
            try {
                await deleteAccount.mutateAsync({ id, permanent: true })
                showSuccess('Cuenta eliminada permanentemente')
            } catch (e) {
                showError('Error al eliminar cuenta')
            }
        }
    }

    // Helper to format bytes
    const formatBytes = (bytes?: number) => {
        if (!bytes) return ''
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
        if (bytes === 0) return '0 Byte'
        const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)).toString())
        return Math.round(bytes / Math.pow(1024, i)) + ' ' + sizes[i]
    }

    const { data: categories } = useCategories()

    // Auto-select first account
    useEffect(() => {
        if (!selectedAccount && accounts && accounts.length > 0) {
            setSelectedAccount(accounts[0].id)
        }
    }, [accounts, selectedAccount])

    // Auto-sync logic
    useEffect(() => {
        const currentAccount = accounts?.find(a => a.id === selectedAccount)
        if (!currentAccount || !currentAccount.auto_sync_interval || currentAccount.auto_sync_interval <= 0) return

        console.log(`Setting up auto-sync every ${currentAccount.auto_sync_interval} minutes`)

        const intervalId = setInterval(() => {
            if (syncState === null) { // Only sync if not already syncing
                console.log('Triggering auto-sync...')
                handleSync()
            }
        }, currentAccount.auto_sync_interval * 60 * 1000)

        return () => clearInterval(intervalId)
    }, [selectedAccount, accounts, syncState]) // Re-run if account updates or sync state changes

    // Query para mensajes filtrados (lo que se muestra en pantalla)
    const { data: messages, isLoading: messagesLoading, refetch: refetchMessages } = useMessages(
        selectedAccount ? {
            account_id: selectedAccount,
            classification_label: (categoryFilter === 'all') ? 'INBOX' : ((categoryFilter !== 'starred' && categoryFilter !== 'deleted') ? categoryFilter : undefined),
            is_starred: categoryFilter === 'starred' ? true : undefined,
            folder: categoryFilter === 'deleted' ? 'Deleted' : undefined,
            ...searchFilters
        } : undefined
    )

    // Query para TODOS los mensajes de la cuenta (para calcular contadores)
    const { data: allMessages } = useMessages(
        selectedAccount ? {
            account_id: selectedAccount
        } : undefined
    )



    const bulkMarkAsRead = useBulkMarkAsRead()
    const emptyFolder = useEmptyFolder()
    const toggleStar = useToggleStar()
    const updateClassification = useUpdateClassification()
    const deleteMessage = useDeleteMessage()

    const handleDragOver = (e: React.DragEvent) => {
        e.preventDefault()
    }

    const handleDrop = async (e: React.DragEvent, target: 'inbox' | 'starred' | 'deleted' | 'category', value?: string) => {
        e.preventDefault()
        const messageId = e.dataTransfer.getData('text/plain')
        if (!messageId) return

        showInfo('Moving message...')

        try {
            if (target === 'inbox') {
                // Clear classification
                await updateClassification.mutateAsync({ messageId, label: null })
                showSuccess('Mensaje movido a Bandeja de Entrada')
            } else if (target === 'starred') {
                // Star the message
                await toggleStar.mutateAsync({ messageId, isStarred: true })
                showSuccess('Mensaje destacado')
            } else if (target === 'deleted') {
                // Delete message
                await deleteMessage.mutateAsync(messageId)
                showSuccess('Mensaje movido a Papelera')
            } else if (target === 'category' && value) {
                // Update classification
                await updateClassification.mutateAsync({ messageId, label: value })
                showSuccess(`Movido a ${value}`)
            }
        } catch (error) {
            showError('Error al mover mensaje')
        }
    }

    const handleSync = async () => {
        if (!selectedAccount) return

        // Initialize state
        setSyncState({
            download: { status: 'active', current: 0, total: 0, message: 'Starting...' },
            classify: { status: 'pending', current: 0, total: 0, message: 'Waiting...' }
        })

        await streamSync(
            {
                account_id: selectedAccount,
                auto_classify: false
            },
            (data) => {
                // Parse incoming events
                if (data.status === 'found_messages') {
                    setSyncState(prev => ({
                        ...prev!,
                        download: {
                            status: 'active',
                            current: 0,
                            total: data.total || 0,
                            message: data.message || `Encontrados ${data.total} mensajes`
                        }
                    }))
                } else if (data.status === 'downloading' || data.status === 'download_progress' || (data.current && !data.status)) {
                    setSyncState(prev => ({
                        ...prev!,
                        download: {
                            status: 'active',
                            current: data.current || 0,
                            total: data.total || 0,
                            message: data.message || 'Descargando...'
                        }
                    }))
                } else if (data.status === 'classifying' || data.status === 'classifying_progress') {
                    // Mark download as complete if moving to classify
                    setSyncState(prev => ({
                        download: { ...prev!.download, status: 'complete', message: 'Descarga completa' },
                        classify: {
                            status: 'active',
                            current: data.current || 0,
                            total: data.total || 0,
                            message: data.message || 'Analizando...'
                        }
                    }))
                } else if (data.status === 'complete') {
                    const result = data.sync_result
                    const newCount = result?.new_messages || 0
                    const classified = data.classified_count || 0

                    setSyncState(prev => ({
                        download: { ...prev!.download, status: 'complete', current: prev!.download.total, total: prev!.download.total },
                        classify: { ...prev!.classify, status: 'complete', current: classified, total: classified }
                    }))

                    if (classified > 0) {
                        showSuccess(`Sincronizados ${newCount} mensajes y clasificados ${classified}`)
                    } else {
                        showSuccess(`Sincronizados ${newCount} nuevos mensajes`)
                    }

                    // Refresh ALL messages data (counts and list)
                    queryClient.invalidateQueries({ queryKey: ['messages'] })

                    // Auto close after 3 seconds
                    setTimeout(() => setSyncState(null), 5000)

                } else if (data.status === 'error') {
                    showError(data.error || 'Fallo en sincronización')
                    setSyncState(prev => ({
                        ...prev!,
                        download: { ...prev!.download, status: 'error', message: data.error }
                    }))
                }
            },
            (error) => {
                console.error('Sync stream error:', error)
                showError('Sync connection failed')
                setSyncState(null)
            }
        )
    }

    const handleMessageClick = (message: Message) => {
        setSelectedMessage(message)
    }

    const handleReply = (message: Message) => {
        setComposerMode('reply')
        setComposerOriginalMessage(message)
        setShowComposer(true)
    }

    const handleForward = (message: Message) => {
        setComposerMode('forward')
        setComposerOriginalMessage(message)
        setShowComposer(true)
    }

    const handleNewEmail = () => {
        setComposerMode('new')
        setComposerOriginalMessage(null)
        setShowComposer(true)
    }

    const handleBulkClassify = async () => {
        if (!selectedAccount) return

        setBulkClassifying(true)
        showInfo('Starting bulk classification...')

        try {
            // Get all unclassified messages
            const unclassifiedMessages = messages?.filter(m => !m.classification_label) || []

            if (unclassifiedMessages.length === 0) {
                showInfo('No unclassified messages found')
                setBulkClassifying(false)
                return
            }

            let classified = 0
            let failed = 0

            // Classify in batches of 5 to avoid overwhelming the server
            for (let i = 0; i < unclassifiedMessages.length; i += 5) {
                const batch = unclassifiedMessages.slice(i, i + 5)

                await Promise.allSettled(
                    batch.map(async (msg) => {
                        try {
                            await axios.post(`http://localhost:8000/api/classify/${msg.id}`)
                            classified++
                        } catch (error) {
                            failed++
                        }
                    })
                )

                // Update progress
                const progress = Math.min(i + 5, unclassifiedMessages.length)
                showInfo(`Classifying... ${progress}/${unclassifiedMessages.length}`)
            }

            refetchMessages()
            showSuccess(`Bulk classification complete! Classified: ${classified}, Failed: ${failed}`)
        } catch (error: any) {
            showError('Bulk classification failed')
        } finally {
            setBulkClassifying(false)
        }
    }

    const handleMarkAllRead = async () => {
        if (!selectedAccount) return

        try {
            const result = await bulkMarkAsRead.mutateAsync({
                accountId: selectedAccount,
                classificationLabel: categoryFilter === 'all' ? undefined : categoryFilter
            })

            showSuccess(`Marked ${result.updated} messages as read`)
        } catch (error: any) {
            showError('Failed to mark messages as read')
        }
    }

    const handleEmptyFolder = async () => {
        if (!selectedAccount) return

        const folderName = getCategoryTitle()
        if (!confirm(`Are you sure you want to empty ${folderName}?`)) return

        try {
            await emptyFolder.mutateAsync({
                accountId: selectedAccount,
                folder: categoryFilter === 'deleted' ? 'Deleted' : (categoryFilter === 'all' ? 'INBOX' : undefined),
                classificationLabel: (categoryFilter !== 'all' && categoryFilter !== 'deleted' && categoryFilter !== 'starred') ? categoryFilter : undefined
            })
            showSuccess(`Folder ${folderName} emptied`)
        } catch (error) {
            showError('Failed to empty folder')
        }
    }

    const handleSearch = (filters: any) => {
        setSearchFilters(filters)
    }

    const handleClearSearch = () => {
        setSearchFilters({})
    }

    const handleCategoryClick = (category: CategoryFilter) => {
        setCategoryFilter(category)
    }

    // Calculate message counts
    const getMessageCounts = (filter: CategoryFilter) => {
        if (!allMessages) return { total: 0, unread: 0 }

        const filtered = filter === 'all'
            ? allMessages.filter(m => !m.classification_label)  // Inbox: solo mensajes sin clasificar
            : allMessages.filter(m => m.classification_label === filter)

        return {
            total: filtered.length,
            unread: filtered.filter(m => !m.is_read).length
        }
    }

    // Counts are now calculated dynamically in the render loop
    const allCounts = getMessageCounts('all')

    const getCategoryTitle = () => {
        switch (categoryFilter) {
            case 'all': return 'Bandeja de Entrada'
            case 'starred': return 'Destacados'
            case 'deleted': return 'Papelera'
            default:
                const cat = categories?.find((c: any) => c.key === categoryFilter)
                return cat ? cat.name : categoryFilter
        }
    }

    if (accountsLoading) {
        return <div className="app loading">Loading accounts...</div>
    }

    if (!accounts || accounts.length === 0) {
        return (
            <div className="app">
                <header className="app-header">
                    <h1>📧 Hawkins Mail</h1>
                    <p className="subtitle">No accounts configured</p>
                    <button onClick={logout} style={{ fontSize: '0.8rem', padding: '0.3rem' }}>Logout ({user?.username})</button>
                </header>
                <main className="app-main">
                    <div className="info-card">
                        <h3>Get Started</h3>
                        <p>Add your first email account to begin managing your emails.</p>
                        <button onClick={() => setShowAccountManager(true)} className="btn-primary">
                            Add Account
                        </button>
                    </div>
                </main>
                {showAccountManager && (
                    <AccountManager onClose={() => setShowAccountManager(false)} />
                )}
            </div>
        )
    }

    return (
        <div className="app mail-app">
            <aside className="sidebar">
                <div className="sidebar-header">
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%' }}>
                        <h2>📧 Hawkins</h2>
                        {user?.is_admin && <button onClick={() => navigate('/admin')} style={{ fontSize: '0.8rem', padding: '0.2rem', cursor: 'pointer' }} title="Admin Area">🔑</button>}
                    </div>
                    <div style={{ fontSize: '0.8rem', color: '#888', marginBottom: '0.5rem' }}>
                        User: {user?.username} <button onClick={logout} style={{ border: 'none', background: 'none', color: 'blue', cursor: 'pointer', padding: 0, textDecoration: 'underline' }}>Logout</button>
                    </div>
                </div>

                <div className="accounts-section">
                    <div className="accounts-header">
                        <h3>Accounts</h3>
                        <button
                            className="btn-icon"
                            onClick={() => setShowSettings(true)}
                            title="Settings"
                        >
                            ⚙️
                        </button>
                    </div>
                    {accounts?.map((account) => (
                        <div key={account.id} style={{ display: 'flex', alignItems: 'center', marginBottom: '5px' }}>
                            <button
                                className={`account-item ${selectedAccount === account.id ? 'active' : ''}`}
                                onClick={() => setSelectedAccount(account.id)}
                                style={{ flexGrow: 1, textAlign: 'left', overflow: 'hidden', textOverflow: 'ellipsis' }}
                            >
                                <div style={{ display: 'flex', flexDirection: 'column' }}>
                                    <span className="account-email">{account.email_address}</span>
                                    {account.mailbox_storage_bytes ?
                                        <span style={{ fontSize: '0.65rem', color: '#888', marginTop: '2px' }}>
                                            💾 {formatBytes(account.mailbox_storage_bytes)}
                                            {account.mailbox_storage_limit ? ` / ${formatBytes(account.mailbox_storage_limit)}` : ''}
                                        </span>
                                        : null}
                                </div>
                            </button>
                            <button
                                onClick={(e) => { e.stopPropagation(); handleDeleteAccount(account.id); }}
                                style={{
                                    background: 'none',
                                    border: 'none',
                                    cursor: 'pointer',
                                    color: '#888',
                                    padding: '5px 8px',
                                    fontSize: '1rem',
                                    opacity: 0.6
                                }}
                                title="Delete Account"
                                onMouseEnter={e => e.currentTarget.style.opacity = '1'}
                                onMouseLeave={e => e.currentTarget.style.opacity = '0.6'}
                            >
                                🗑️
                            </button>
                        </div>
                    ))}

                    {deletedAccounts && deletedAccounts.length > 0 && (
                        <div style={{ marginTop: '1rem', borderTop: '1px solid #ddd', paddingTop: '0.5rem' }}>
                            <h4 style={{ fontSize: '0.8rem', color: '#666', marginBottom: '0.5rem', textTransform: 'uppercase', letterSpacing: '0.5px' }}>Deleted Accounts</h4>
                            <div style={{ maxHeight: '150px', overflowY: 'auto' }}>
                                {deletedAccounts.map(acc => (
                                    <div key={acc.id} style={{ padding: '8px', fontSize: '0.8rem', background: '#ffebee', marginBottom: '5px', borderRadius: '4px', border: '1px solid #ffcdd2' }}>
                                        <div style={{ marginBottom: '5px', fontWeight: '500', color: '#c62828' }}>{acc.email_address}</div>
                                        <div style={{ display: 'flex', gap: '5px' }}>
                                            <button
                                                onClick={() => handleRestoreAccount(acc.id)}
                                                style={{ fontSize: '0.7rem', flex: 1, padding: '2px', cursor: 'pointer' }}
                                                title="Restore account"
                                            >
                                                ♻️ Restore
                                            </button>
                                            <button
                                                onClick={() => handlePermanentDelete(acc.id)}
                                                style={{ fontSize: '0.7rem', flex: 1, padding: '2px', cursor: 'pointer', color: '#c62828' }}
                                                title="Permanently delete"
                                            >
                                                🔥 Delete
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    <button
                        className="btn-secondary"
                        onClick={() => setShowAccountManager(true)}
                        style={{ marginTop: '10px' }}
                    >
                        + Add Account
                    </button>
                </div>

                <div className="folders-section">
                    <div
                        className={`folder-item ${categoryFilter === 'all' ? 'active' : ''}`}
                        onClick={() => handleCategoryClick('all')}
                        onDragOver={handleDragOver}
                        onDrop={(e) => handleDrop(e, 'inbox')}
                    >
                        📥 Bandeja de Entrada
                        <span className="folder-count">
                            {allCounts.unread > 0 && <span className="unread-badge">{allCounts.unread}</span>}
                            <span className="total-count">{allCounts.total}</span>
                        </span>
                    </div>

                    <div
                        className={`folder-item ${categoryFilter === 'starred' ? 'active' : ''}`}
                        onClick={() => handleCategoryClick('starred')}
                        onDragOver={handleDragOver}
                        onDrop={(e) => handleDrop(e, 'starred')}
                    >
                        ⭐ Destacados
                        <span className="folder-count">
                            <span className="total-count">{allMessages?.filter(m => m.is_starred).length || 0}</span>
                        </span>
                    </div>

                    {/* Dynamic Categories */}
                    {categories?.map((category: any) => {
                        const counts = getMessageCounts(category.key as any)
                        return (
                            <div
                                key={category.id}
                                className={`folder-item ${categoryFilter === category.key ? 'active' : ''}`}
                                onClick={() => handleCategoryClick(category.key as any)}
                                onDragOver={handleDragOver}
                                onDrop={(e) => handleDrop(e, 'category', category.key)}
                            >
                                {category.icon || '📁'} {category.name}
                                <span className="folder-count">
                                    {counts.unread > 0 && <span className="unread-badge">{counts.unread}</span>}
                                    <span className="total-count">{counts.total}</span>
                                </span>
                            </div>
                        )
                    })}

                    <div
                        className={`folder-item ${categoryFilter === 'deleted' ? 'active' : ''}`}
                        onClick={() => handleCategoryClick('deleted')}
                        onDragOver={handleDragOver}
                        onDrop={(e) => handleDrop(e, 'deleted')}
                    >
                        🗑️ Mensajes eliminados
                        <span className="folder-count">
                            <span className="total-count">{allMessages?.filter(m => m.folder === 'Deleted').length || 0}</span>
                        </span>
                    </div>
                </div>
            </aside >

            <main className="main-content">
                <div className="toolbar">
                    <h2>{getCategoryTitle()}</h2>
                    <div className="toolbar-actions">
                        <button
                            className="btn-secondary"
                            onClick={handleNewEmail}
                            disabled={!selectedAccount}
                        >
                            ✉️ Redactar
                        </button>
                        <button
                            className="btn-toolbar"
                            onClick={handleBulkClassify}
                            disabled={!selectedAccount || bulkClassifying}
                            title="Classify all unclassified messages with AI"
                        >
                            {bulkClassifying ? '⏳ Analizando...' : '🤖 Clasificar Todo'}
                        </button>
                        <button
                            className="btn-toolbar"
                            onClick={handleMarkAllRead}
                            disabled={!selectedAccount}
                            title="Mark all messages in this folder as read"
                        >
                            ✅ Marcar Leídos
                        </button>
                        <button
                            className="btn-toolbar"
                            onClick={handleEmptyFolder}
                            disabled={!selectedAccount}
                            title="Move all messages in this folder to Trash"
                        >
                            🗑️ Vaciar {getCategoryTitle()}
                        </button>
                        <button
                            className="btn-primary"
                            onClick={handleSync}
                            disabled={!selectedAccount || (syncState !== null)}
                        >
                            {syncState ? '🔄 Sincronizando...' : '🔄 Sincronizar'}
                        </button>
                    </div>
                </div>

                {
                    syncState && (
                        <SyncStatus
                            download={syncState.download}
                            classify={syncState.classify}
                            onClose={() => setSyncState(null)}
                        />
                    )
                }

                {
                    !selectedAccount ? (
                        <div className="empty-state">
                            <p>Selecciona una cuenta para ver mensajes</p>
                        </div>
                    ) : (
                        <>
                            <SearchBar onSearch={handleSearch} onClear={handleClearSearch} />
                            {messagesLoading ? (
                                <div className="loading-state">Cargando mensajes...</div>
                            ) : (
                                <MessageList
                                    messages={messages || []}
                                    onMessageClick={handleMessageClick}
                                />
                            )}
                        </>
                    )
                }
            </main >

            {
                showAccountManager && (
                    <AccountManager onClose={() => setShowAccountManager(false)} />
                )
            }

            {
                selectedMessage && (
                    <MessageViewer
                        message={selectedMessage}
                        onClose={() => setSelectedMessage(null)}
                        onReply={handleReply}
                        onForward={handleForward}
                    />
                )
            }

            {
                showComposer && (
                    <Composer
                        onClose={() => setShowComposer(false)}
                        mode={composerMode}
                        originalMessage={composerOriginalMessage || undefined}
                    />
                )
            }

            {
                showSettings && (
                    <Settings onClose={() => setShowSettings(false)} />
                )
            }

            {/* Toast notifications */}
            <div className="toast-container">
                {toasts.map((toast) => (
                    <Toast
                        key={toast.id}
                        id={toast.id}
                        type={toast.type}
                        message={toast.message}
                        onClose={removeToast}
                    />
                ))}
            </div>
        </div >
    )
}

export default Dashboard
