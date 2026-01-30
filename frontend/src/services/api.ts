/**
 * API client - Extended with new endpoints
 */
import axios from 'axios';

const API_BASE_URL = 'http://localhost:8000';

export const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  timeout: 10000,
});

const TOKEN_KEY = 'auth_token';
export const setToken = (token: string) => localStorage.setItem(TOKEN_KEY, token);
export const getToken = () => localStorage.getItem(TOKEN_KEY);
export const removeToken = () => localStorage.removeItem(TOKEN_KEY);

apiClient.interceptors.request.use((config) => {
  const token = getToken();
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Health check
export const healthCheck = async () => {
  const response = await apiClient.get('/health');
  return response.data;
};

// Accounts
export interface Account {
  id: number;
  email_address: string;
  imap_host: string;
  imap_port: number;
  smtp_host: string;
  smtp_port: number;
  username: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  auto_classify?: boolean;
  auto_sync_interval?: number;
  custom_classification_prompt?: string;
  custom_review_prompt?: string;
  owner_profile?: string; // User persona
  is_deleted: boolean;
  protocol: 'imap' | 'pop3';
  mailbox_storage_bytes?: number;
  mailbox_storage_limit?: number;
}

export interface AccountCreate {
  email_address: string;
  imap_host: string;
  imap_port: number;
  smtp_host: string;
  smtp_port: number;
  username: string;
  password: string;
  protocol?: 'imap' | 'pop3';
}

export const getAccounts = async (deleted: boolean = false): Promise<Account[]> => {
  try {
    const response = await apiClient.get('/api/accounts/', { params: { deleted } });
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Returning mock account.');
    return [{
      id: 1,
      email_address: 'ivan@hawkins.es',
      imap_host: 'imap.hawkins.es',
      imap_port: 993,
      smtp_host: 'smtp.hawkins.es',
      smtp_port: 587,
      username: 'ivan@hawkins.es',
      is_active: true,
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
      is_deleted: false,
      protocol: 'imap'
    }];
  }
};

export const deleteAccount = async (id: number, permanent: boolean = false) => {
  await apiClient.delete(`/api/accounts/${id}`, { params: { permanent } });
};

export const restoreAccount = async (id: number) => {
  const response = await apiClient.post(`/api/accounts/${id}/restore`);
  return response.data;
};

export interface Message {
  id: string;
  account_id: number;
  from_email: string;
  from_name?: string;
  subject?: string;
  date: string;
  snippet?: string;
  is_read: boolean;
  is_starred: boolean;
  has_attachments: boolean;
  classification_label?: string;
  folder: string;
}

export interface MessageDetail extends Message {
  to_addresses: string;
  cc_addresses: string;
  bcc_addresses?: string;
  body_text?: string;
  body_html?: string;
  message_id: string;
}


export const createAccount = async (data: AccountCreate): Promise<Account> => {
  const response = await apiClient.post('/api/accounts/', data);
  return response.data;

};

export const updateAccount = async (id: number, data: Partial<AccountCreate> & { auto_classify?: boolean }): Promise<Account> => {
  const response = await apiClient.put(`/api/accounts/${id}`, data);
  return response.data;
};

export const getMessages = async (params?: {
  account_id?: number;
  folder?: string;
  classification_label?: string;  // Filter by classification category
  search?: string;
}): Promise<Message[]> => {
  try {
    const response = await apiClient.get('/api/messages/', { params });
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Returning mock messages.');
    return [
      {
        id: '1',
        account_id: 1,
        from_email: 'noreply@google.com',
        from_name: 'Google',
        subject: 'Security Alert',
        date: new Date().toISOString(),
        snippet: 'New sign-in to your account...',
        is_read: false,
        is_starred: false,
        has_attachments: false,
        classification_label: 'Servicios',
        folder: 'INBOX'
      },
      {
        id: '2',
        account_id: 1,
        from_email: 'client@example.com',
        from_name: 'Important Client',
        subject: 'Project Proposal',
        date: new Date().toISOString(),
        snippet: 'Please find attached the proposal...',
        is_read: true,
        is_starred: true,
        has_attachments: true,
        classification_label: 'Interesantes',
        folder: 'INBOX'
      }
    ];
  }
};

export const getMessage = async (id: string): Promise<MessageDetail> => {
  const response = await apiClient.get(`/api/messages/${id}`);
  return response.data;
};

export const updateMessage = async (id: string, data: { is_read?: boolean; is_starred?: boolean }) => {
  const response = await apiClient.patch(`/api/messages/${id}`, data);
  return response.data;
};

export const deleteMessage = async (id: string) => {
  const response = await apiClient.delete(`/api/messages/${id}`);
  return response.data;
};


// Sync
export interface SyncRequest {
  account_id: number;
  folder?: string;
  auto_classify?: boolean;  // Automatically classify new messages after sync
}

export const startSync = async (data: SyncRequest) => {
  const response = await apiClient.post('/api/sync/start/', data);
  return response.data;
};

export const getSyncStatus = async () => {
  const response = await apiClient.get('/api/sync/status');
  return response.data;
};

export const streamSync = async (
  data: SyncRequest,
  onMessage: (data: any) => void,
  onError: (error: any) => void
) => {
  try {
    const token = getToken();
    const headers: HeadersInit = {
      'Content-Type': 'application/json',
    };

    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE_URL}/api/sync/stream`, {
      method: 'POST',
      headers,
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const reader = response.body?.getReader();
    const decoder = new TextDecoder();

    if (!reader) throw new Error('No reader available');

    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      const chunk = decoder.decode(value, { stream: true });
      buffer += chunk;

      const lines = buffer.split('\n\n');
      // Keep the last part if it's incomplete
      buffer = lines.pop() || '';

      for (const line of lines) {
        if (line.trim().startsWith('data: ')) {
          const jsonStr = line.trim().slice(6);
          if (!jsonStr) continue;
          try {
            const parsed = JSON.parse(jsonStr);
            onMessage(parsed);
          } catch (e) {
            console.error('Error parsing SSE', e);
          }
        }
      }
    }
  } catch (err) {
    onError(err);
  }
};

// Classification (placeholder for future)
export interface Classification {
  id: number;
  message_id: string;
  gpt_label?: string;
  gpt_confidence?: number;
  gpt_rationale?: string;
  qwen_label?: string;
  qwen_confidence?: number;
  qwen_rationale?: string;
  final_label: string;
  final_reason?: string;
  decided_by: string;
  decided_at: string;
}

export const getClassification = async (messageId: string): Promise<Classification> => {
  const response = await apiClient.get(`/api/classifications/${messageId}`);
  return response.data;
};

export const updateClassification = async (messageId: string, label: string | null) => {
  const response = await apiClient.put(`/api/messages/${messageId}/classification`, null, {
    params: { label }
  });
  return response.data;
};

// Categories
export interface Category {
  id: number;
  key: string;
  name: string;
  description?: string;
  ai_instruction: string;
  icon?: string;
  is_system: boolean;
}

export interface CategoryCreate {
  key: string;
  name: string;
  description?: string;
  ai_instruction: string;
  icon?: string;
}

export interface CategoryUpdate {
  name?: string;
  description?: string;
  ai_instruction?: string;
  icon?: string;
}

// Mock storage for session capabilities
let MOCK_CATEGORIES: Category[] = [
  { id: 1, key: 'interesantes', name: 'Interesantes', ai_instruction: 'Budget requests', is_system: true, icon: '⭐' },
  { id: 2, key: 'spam', name: 'SPAM', ai_instruction: 'Unwanted', is_system: true, icon: '🚫' },
  { id: 3, key: 'encopia', name: 'EnCopia', ai_instruction: 'CCed emails', is_system: true, icon: '👥' },
  { id: 4, key: 'servicios', name: 'Servicios', ai_instruction: 'Transactional', is_system: true, icon: '⚙️' }
];

export const getCategories = async (): Promise<Category[]> => {
  try {
    const response = await apiClient.get('/api/categories/');
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Returning mock categories.');
    return [...MOCK_CATEGORIES];
  }
};

export const createCategory = async (data: CategoryCreate): Promise<Category> => {
  try {
    const response = await apiClient.post('/api/categories/', data);
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Mocking category creation.');
    const newCategory: Category = {
      id: Date.now(),
      ...data,
      is_system: false
    };
    MOCK_CATEGORIES.push(newCategory);
    return newCategory;
  }
};

export const updateCategory = async (id: number, data: CategoryUpdate): Promise<Category> => {
  try {
    const response = await apiClient.put(`/api/categories/${id}`, data);
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Mocking category update.');
    const index = MOCK_CATEGORIES.findIndex(c => c.id === id);
    if (index !== -1) {
      MOCK_CATEGORIES[index] = { ...MOCK_CATEGORIES[index], ...data };
      return MOCK_CATEGORIES[index];
    }
    throw new Error('Category not found');
  }
};

export const deleteCategory = async (id: number) => {
  try {
    const response = await apiClient.delete(`/api/categories/${id}`);
    return response.data;
  } catch (error) {
    console.warn('Backend unavailable. Mocking category deletion.');
    MOCK_CATEGORIES = MOCK_CATEGORIES.filter(c => c.id !== id);
    return { success: true };
  }
};

// Auth
export interface User {
  id: number;
  username: string;
  is_active: boolean;
  is_admin: boolean;
  mailbox_usage_bytes?: number;
}


export const login = async (username: string, password: string): Promise<{ access_token: string }> => {
  const params = new URLSearchParams();
  params.append('username', username);
  params.append('password', password);

  const response = await apiClient.post('/api/auth/token', params, {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
  });
  return response.data;
};

export const getCurrentUser = async (): Promise<User> => {
  const response = await apiClient.get('/api/auth/users/me');
  return response.data;
};

export const getUsers = async (deleted: boolean = false): Promise<User[]> => {
  const response = await apiClient.get('/api/users/', { params: { deleted } });
  return response.data;
};

export const createUser = async (data: any): Promise<User> => {
  const response = await apiClient.post('/api/users/', data);
  return response.data;
};

export const deleteUser = async (id: number, permanent: boolean = false) => {
  await apiClient.delete(`/api/users/${id}`, { params: { permanent } });
};

export const restoreUser = async (id: number) => {
  return response.data;
};

export const updateUserPassword = async (userId: number, password: string) => {
  const response = await apiClient.put(`/api/users/${userId}/password`, { password });
  return response.data;
};

// ============================================
// AI CONFIGURATION  
// ============================================

export interface AIConfig {
  api_url: string;
  primary_model: string;
  secondary_model: string;
}

export interface AIConfigUpdate extends AIConfig {
  api_key: string;
}

export const getAIConfig = async (): Promise<AIConfig> => {
  const response = await apiClient.get('/api/ai-config');
  return response.data;
};

export const updateAIConfig = async (config: AIConfigUpdate) => {
  const response = await apiClient.put('/api/ai-config', config);
  return response.data;
};

export const getAvailableModels = async (): Promise<string[]> => {
  const response = await apiClient.get('/api/ai-config/models');
  return response.data.models;
};
