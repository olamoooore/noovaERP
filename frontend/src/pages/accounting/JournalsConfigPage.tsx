import React, { useState, useEffect } from 'react';
import { Plus, Edit, Trash2, BookOpen } from 'lucide-react';
import axios from 'axios';
import JournalForm from '../../components/accounting/JournalForm';

interface Journal {
  id: number;
  code: string;
  name: string;
  type: string;
  sequence_prefix: string;
  sequence_number: number;
  default_debit_account?: {
    id: number;
    code: string;
    name: string;
  };
  default_credit_account?: {
    id: number;
    code: string;
    name: string;
  };
  is_active: boolean;
}

const JournalsConfigPage: React.FC = () => {
  const [journals, setJournals] = useState<Journal[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editingJournal, setEditingJournal] = useState<Journal | null>(null);

  useEffect(() => {
    fetchJournals();
  }, []);

  const fetchJournals = async () => {
    try {
      setLoading(true);
      const response = await axios.get('/api/accounting/journals');
      setJournals(response.data);
    } catch (error) {
      console.error('Error fetching journals:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleCreate = () => {
    setEditingJournal(null);
    setShowForm(true);
  };

  const handleEdit = (journal: Journal) => {
    setEditingJournal(journal);
    setShowForm(true);
  };

  const handleDelete = async (journal: Journal) => {
    if (!confirm(`Are you sure you want to delete journal ${journal.code} - ${journal.name}?`)) {
      return;
    }

    try {
      await axios.delete(`/api/accounting/journals/${journal.id}`);
      fetchJournals();
    } catch (error: any) {
      alert(error.response?.data?.message || 'Error deleting journal');
    }
  };

  const handleFormClose = (saved: boolean) => {
    setShowForm(false);
    setEditingJournal(null);
    if (saved) {
      fetchJournals();
    }
  };

  const getTypeColor = (type: string) => {
    const colors: Record<string, string> = {
      sales: 'bg-green-100 text-green-800',
      purchases: 'bg-blue-100 text-blue-800',
      cash: 'bg-purple-100 text-purple-800',
      bank: 'bg-indigo-100 text-indigo-800',
      general: 'bg-gray-100 text-gray-800',
    };
    return colors[type] || 'bg-gray-100 text-gray-800';
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-gray-500">Loading journals...</div>
      </div>
    );
  }

  return (
    <div className="p-6">
      {/* Header */}
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Journals Configuration</h1>
        <p className="text-gray-600 mt-1">Manage journal types and sequences</p>
      </div>

      {/* Create Button */}
      <div className="mb-6 flex justify-end">
        <button
          onClick={handleCreate}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
        >
          <Plus className="w-5 h-5" />
          New Journal
        </button>
      </div>

      {/* Journals List */}
      <div className="bg-white rounded-lg shadow-sm overflow-hidden">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Code
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Name
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Type
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Sequence
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Default Accounts
              </th>
              <th className="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                Status
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {journals.map((journal) => (
              <tr key={journal.id} className="hover:bg-gray-50">
                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  {journal.code}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {journal.name}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <span className={`px-2 py-1 text-xs font-medium rounded-full ${getTypeColor(journal.type)}`}>
                    {journal.type}
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <div className="flex items-center gap-2">
                    <BookOpen className="w-4 h-4 text-gray-400" />
                    <span className="font-mono">{journal.sequence_prefix}-{String(journal.sequence_number).padStart(4, '0')}</span>
                  </div>
                </td>
                <td className="px-6 py-4 text-sm text-gray-900">
                  {journal.default_debit_account && (
                    <div className="text-xs">
                      <span className="text-gray-500">DR:</span> {journal.default_debit_account.code}
                    </div>
                  )}
                  {journal.default_credit_account && (
                    <div className="text-xs">
                      <span className="text-gray-500">CR:</span> {journal.default_credit_account.code}
                    </div>
                  )}
                  {!journal.default_debit_account && !journal.default_credit_account && (
                    <span className="text-gray-400 text-xs">None</span>
                  )}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-center">
                  <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                    journal.is_active
                      ? 'bg-green-100 text-green-800'
                      : 'bg-gray-100 text-gray-800'
                  }`}>
                    {journal.is_active ? 'Active' : 'Inactive'}
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div className="flex justify-end gap-2">
                    <button
                      onClick={() => handleEdit(journal)}
                      className="text-blue-600 hover:text-blue-900"
                    >
                      <Edit className="w-4 h-4" />
                    </button>
                    <button
                      onClick={() => handleDelete(journal)}
                      className="text-red-600 hover:text-red-900"
                    >
                      <Trash2 className="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        {journals.length === 0 && (
          <div className="text-center py-12 text-gray-500">
            No journals configured. Create your first journal to get started.
          </div>
        )}
      </div>

      {/* Journal Form Modal */}
      {showForm && (
        <JournalForm
          journal={editingJournal}
          onClose={handleFormClose}
        />
      )}
    </div>
  );
};

export default JournalsConfigPage;
