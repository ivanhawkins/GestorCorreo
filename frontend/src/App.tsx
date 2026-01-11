import { useState } from 'react'
import './App.css'
import { useAccounts, useMessages, useStartSync, useBulkMarkAsRead } from './hooks/useApi'
import type { Message } from './services/api'
import axios from 'axios'
import AccountManager from './components/AccountManager'
import MessageList from './components/MessageList'
import MessageViewer from './components/MessageViewer'
import Composer from './components/Composer'
import Settings from './components/Settings'
import SearchBar from './components/SearchBar'
import Toast from './components/Toast'
import { useToast } from './hooks/useToast'

type CategoryFilter = 'all' | 'starred' | 'Interesantes' | 'SPAM' | 'EnCopia' | 'Servicios'

function App() {
  const [selectedAccount, setSelectedAccount] = useState<number | null>(null)
  const [showAccountManager, setShowAccountManager] = useState(false)
  const [selectedMessage, setSelectedMessage] = useState<Message | null>(null)
  const [categoryFilter, setCategoryFilter] = useState<CategoryFilter>('all')
  const [autoClassify, setAutoClassify] = useState(false)
  const [showComposer, setShowComposer] = useState(false)
  const [showSettings, setShowSettings] = useState(false)
  const [bulkClassifying, setBulkClassifying] = useState(false)
  const [composerMode, setComposerMode] = useState<'new' | 'reply' | 'forward'>('new')
  const [composerOriginalMessage, setComposerOriginalMessage] = useState<Message | null>(null)
  const [searchFilters, setSearchFilters] = useState<any>({})

  const { toasts, removeToast, showSuccess, showError, showInfo } = useToast()

  const { data: accounts, isLoading: accountsLoading } = useAccounts()

  // Query para mensajes filtrados (lo que se muestra en pantalla)
  const { data: messages, isLoading: messagesLoading, refetch: refetchMessages } = useMessages(
    selectedAccount ? {
      account_id: selectedAccount,
      classification_label: (categoryFilter !== 'all' && categoryFilter !== 'starred') ? categoryFilter : undefined,
      is_starred: categoryFilter === 'starred' ? true : undefined,
      ...searchFilters
    } : undefined
  )

  // Query para TODOS los mensajes de la cuenta (para calcular contadores)
  const { data: allMessages } = useMessages(
    selectedAccount ? {
      account_id: selectedAccount
    } : undefined
  )

  const startSync = useStartSync()
  const bulkMarkAsRead = useBulkMarkAsRead()

  const handleSync = async () => {
    if (!selectedAccount) return

    showInfo('Synchronizing messages...')

    try {
      const result = await startSync.mutateAsync({
        account_id: selectedAccount,
        auto_classify: autoClassify
      })

      const newCount = result.new_messages || 0
      const classifiedCount = result.classified_count || 0

      if (autoClassify && classifiedCount > 0) {
        showSuccess(`Synced ${newCount} messages and classified ${classifiedCount}`)
      } else {
        showSuccess(`Synced ${newCount} new messages`)
      }

      refetchMessages()
    } catch (error: any) {
      showError(error?.response?.data?.detail || 'Failed to sync messages')
      console.error('Sync error:', error)
    }
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

  const allCounts = getMessageCounts('all')
  const interesantesCounts = getMessageCounts('Interesantes')
  const spamCounts = getMessageCounts('SPAM')
  const enCopiaCounts = getMessageCounts('EnCopia')
  const serviciosCounts = getMessageCounts('Servicios')

  const getCategoryTitle = () => {
    switch (categoryFilter) {
      case 'all': return 'Inbox'
      case 'starred': return 'Starred'
      case 'Interesantes': return 'Interesantes'
      case 'SPAM': return 'SPAM'
      case 'EnCopia': return 'En Copia'
      case 'Servicios': return 'Servicios'
      default: return 'Messages'
    }
  }

  if (accountsLoading) {
    return <div className="app loading">Loading accounts...</div>
  }

  if (!accounts || accounts.length === 0) {
    return (
      <div className="app">
        <header className="app-header">
          <h1>📧 Mail Manager</h1>
          <p className="subtitle">No accounts configured</p>
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
          <h2>📧 Mail Manager</h2>
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
          {accounts.map((account) => (
            <button
              key={account.id}
              className={`account-item ${selectedAccount === account.id ? 'active' : ''}`}
              onClick={() => setSelectedAccount(account.id)}
            >
              <span className="account-email">{account.email_address}</span>
            </button>
          ))}
          <button
            className="btn-secondary"
            onClick={() => setShowAccountManager(true)}
          >
            + Add Account
          </button>
        </div>

        <div className="folders-section">
          <div
            className={`folder-item ${categoryFilter === 'all' ? 'active' : ''}`}
            onClick={() => handleCategoryClick('all')}
          >
            📥 Inbox
            <span className="folder-count">
              {allCounts.unread > 0 && <span className="unread-badge">{allCounts.unread}</span>}
              <span className="total-count">{allCounts.total}</span>
            </span>
          </div>
          <div
            className={`folder-item ${categoryFilter === 'starred' ? 'active' : ''}`}
            onClick={() => handleCategoryClick('starred')}
          >
            ⭐ Starred
            <span className="folder-count">
              <span className="total-count">{allMessages?.filter(m => m.is_starred).length || 0}</span>
            </span>
          </div>

          <div
            className={`folder-item ${categoryFilter === 'Interesantes' ? 'active' : ''}`}
            onClick={() => handleCategoryClick('Interesantes')}
          >
            ⭐ Interesantes
            <span className="folder-count">
              {interesantesCounts.unread > 0 && <span className="unread-badge">{interesantesCounts.unread}</span>}
              <span className="total-count">{interesantesCounts.total}</span>
            </span>
          </div>
          <div
            className={`folder-item ${categoryFilter === 'SPAM' ? 'active' : ''}`}
            onClick={() => handleCategoryClick('SPAM')}
          >
            🚫 SPAM
            <span className="folder-count">
              {spamCounts.unread > 0 && <span className="unread-badge">{spamCounts.unread}</span>}
              <span className="total-count">{spamCounts.total}</span>
            </span>
          </div>
          <div
            className={`folder-item ${categoryFilter === 'EnCopia' ? 'active' : ''}`}
            onClick={() => handleCategoryClick('EnCopia')}
          >
            📋 EnCopia
            <span className="folder-count">
              {enCopiaCounts.unread > 0 && <span className="unread-badge">{enCopiaCounts.unread}</span>}
              <span className="total-count">{enCopiaCounts.total}</span>
            </span>
          </div>
          <div
            className={`folder-item ${categoryFilter === 'Servicios' ? 'active' : ''}`}
            onClick={() => handleCategoryClick('Servicios')}
          >
            🔔 Servicios
            <span className="folder-count">
              {serviciosCounts.unread > 0 && <span className="unread-badge">{serviciosCounts.unread}</span>}
              <span className="total-count">{serviciosCounts.total}</span>
            </span>
          </div>
        </div>
      </aside>

      <main className="main-content">
        <div className="toolbar">
          <h2>{getCategoryTitle()}</h2>
          <div className="toolbar-actions">
            <button
              className="btn-secondary"
              onClick={handleNewEmail}
              disabled={!selectedAccount}
            >
              ✉️ Compose
            </button>
            <button
              className="btn-ai"
              onClick={handleBulkClassify}
              disabled={!selectedAccount || bulkClassifying}
              title="Classify all unclassified messages with AI"
            >
              {bulkClassifying ? '⏳ Analyzing...' : '🤖 Bulk Classify'}
            </button>
            <button
              className="btn-secondary"
              onClick={handleMarkAllRead}
              disabled={!selectedAccount}
              title="Mark all messages in this folder as read"
            >
              ✅ Mark All Read
            </button>
            <label className="auto-classify-toggle">
              <input
                type="checkbox"
                checked={autoClassify}
                onChange={(e) => setAutoClassify(e.target.checked)}
              />
              <span>Auto-classify</span>
            </label>
            <button
              className="btn-primary"
              onClick={handleSync}
              disabled={!selectedAccount || startSync.isPending}
            >
              {startSync.isPending ? '⏳ Syncing...' : '🔄 Sync'}
            </button>
          </div>
        </div>

        {!selectedAccount ? (
          <div className="empty-state">
            <p>Select an account to view messages</p>
          </div>
        ) : (
          <>
            <SearchBar onSearch={handleSearch} onClear={handleClearSearch} />
            {messagesLoading ? (
              <div className="loading-state">Loading messages...</div>
            ) : (
              <MessageList
                messages={messages || []}
                onMessageClick={handleMessageClick}
              />
            )}
          </>
        )}
      </main>

      {showAccountManager && (
        <AccountManager onClose={() => setShowAccountManager(false)} />
      )}

      {selectedMessage && (
        <MessageViewer
          message={selectedMessage}
          onClose={() => setSelectedMessage(null)}
          onReply={handleReply}
          onForward={handleForward}
        />
      )}

      {showComposer && (
        <Composer
          onClose={() => setShowComposer(false)}
          mode={composerMode}
          originalMessage={composerOriginalMessage || undefined}
        />
      )}

      {showSettings && (
        <Settings onClose={() => setShowSettings(false)} />
      )}

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
    </div>
  )
}

export default App
