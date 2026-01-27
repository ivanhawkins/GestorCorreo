import { useState, useEffect } from 'react'
import './App.css'
import { useAccounts, useMessages, useStartSync, useBulkMarkAsRead, useCategories, streamSync } from './hooks/useApi'
import type { Message } from './services/api'
import axios from 'axios'
import AccountManager from './components/AccountManager'
import MessageList from './components/MessageList'
import MessageViewer from './components/MessageViewer'
import Composer from './components/Composer'
import Settings from './components/Settings'
import SearchBar from './components/SearchBar'
import SyncStatus from './components/SyncStatus'
import Toast from './components/Toast'
import { useToast } from './hooks/useToast'
import { useToggleStar, useUpdateClassification, useDeleteMessage } from './hooks/useApi'

type CategoryFilter = 'all' | 'starred' | 'deleted' | string

function App() {
  console.log('App component rendering...');
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


  // Dual sync state
  const [syncState, setSyncState] = useState<{
    download: { status: 'pending' | 'active' | 'complete' | 'error'; current: number; total: number; message?: string };
    classify: { status: 'pending' | 'active' | 'complete' | 'error'; current: number; total: number; message?: string };
  } | null>(null)

  const { toasts, removeToast, showSuccess, showError, showInfo } = useToast()

  const { data: accounts, isLoading: accountsLoading } = useAccounts()
  const { data: categories } = useCategories()

  // Auto-select first account
  useEffect(() => {
    if (!selectedAccount && accounts && accounts.length > 0) {
      setSelectedAccount(accounts[0].id)
    }
  }, [accounts, selectedAccount])

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
        showSuccess('Message moved to Inbox')
      } else if (target === 'starred') {
        // Star the message
        await toggleStar.mutateAsync({ messageId, isStarred: true })
        showSuccess('Message starred')
      } else if (target === 'deleted') {
        // Delete message
        await deleteMessage.mutateAsync(messageId)
        showSuccess('Message moved to Deleted')
      } else if (target === 'category' && value) {
        // Update classification
        await updateClassification.mutateAsync({ messageId, label: value })
        showSuccess(`Moved to ${value}`)
      }
    } catch (error) {
      showError('Failed to move message')
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
        auto_classify: autoClassify
      },
      (data) => {
        // Parse incoming events
        if (data.status === 'download_progress' || (data.current && !data.status)) { // Fallback for old events
          setSyncState(prev => ({
            ...prev!,
            download: {
              status: 'active',
              current: data.current || 0,
              total: data.total || 0,
              message: data.message || 'Downloading...'
            }
          }))
        } else if (data.status === 'classifying' || data.status === 'classifying_progress') {
          // Mark download as complete if moving to classify
          setSyncState(prev => ({
            download: { ...prev!.download, status: 'complete', message: 'Download complete' },
            classify: {
              status: 'active',
              current: data.current || 0,
              total: data.total || 0,
              message: data.message || 'Analyzing...'
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

          if (autoClassify && classified > 0) {
            showSuccess(`Synced ${newCount} messages and classified ${classified}`)
          } else {
            showSuccess(`Synced ${newCount} new messages`)
          }

          refetchMessages()

          // Auto close after 3 seconds
          setTimeout(() => setSyncState(null), 5000)

        } else if (data.status === 'error') {
          showError(data.error || 'Sync failed')
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
      case 'all': return 'Inbox'
      case 'starred': return 'Starred'
      case 'deleted': return 'Trash'
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
          <h2>📧 Hawkins Mail</h2>
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
            onDragOver={handleDragOver}
            onDrop={(e) => handleDrop(e, 'inbox')}
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
            onDragOver={handleDragOver}
            onDrop={(e) => handleDrop(e, 'starred')}
          >
            ⭐ Starred
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
              disabled={!selectedAccount || (syncState !== null)}
            >
              {syncState ? '🔄 Syncing...' : '🔄 Sync'}
            </button>
          </div>
        </div>

        {syncState && (
          <SyncStatus
            download={syncState.download}
            classify={syncState.classify}
            onClose={() => setSyncState(null)}
          />
        )}

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

export default App
