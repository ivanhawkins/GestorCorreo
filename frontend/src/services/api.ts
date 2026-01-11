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
}

export interface AccountCreate {
  email_address: string;
  imap_host: string;
  imap_port: number;
  smtp_host: string;
  smtp_port: number;
  username: string;
  password: string;
}

export const getAccounts = async (): Promise<Account[]> => {
  const response = await apiClient.get('/api/accounts/');
  return response.data;
};

export const createAccount = async (data: AccountCreate): Promise<Account> => {
  const response = await apiClient.post('/api/accounts/', data);
  return response.data;
};

export const testConnection = async (accountId: number) => {
  const response = await apiClient.post(`/api/accounts/${accountId}/test`);
  return response.data;
};

// Messages
export interface Message {
  id: string;
  account_id: number;
  from_name?: string;
  from_email: string;
  subject?: string;
  date: string;
  snippet?: string;
  is_read: boolean;
  is_starred: boolean;
  has_attachments: boolean;
  classification_label?: string;  // Classification category if classified
  folder?: string;
}

export interface MessageDetail extends Message {
  to_addresses: string;
  cc_addresses: string;
  bcc_addresses?: string;
  body_text?: string;
  body_html?: string;
  message_id: string;
}

export const getMessages = async (params?: {
  account_id?: number;
  folder?: string;
  classification_label?: string;  // Filter by classification category
  search?: string;
}): Promise<Message[]> => {
  const response = await apiClient.get('/api/messages/', { params });
  return response.data;
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
