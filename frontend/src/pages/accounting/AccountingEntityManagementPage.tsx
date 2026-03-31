import { useQuery } from '@tanstack/react-query';
import { Card } from '@/components/ui/card';
import api from '@/lib/api';

function EntitySection({ title, items, emptyLabel }: { title: string; items: any[]; emptyLabel: string }) {
  return (
    <Card className="p-6">
      <h2 className="text-lg font-semibold mb-4">{title}</h2>
      {!items || items.length === 0 ? (
        <div className="text-sm text-muted-foreground">{emptyLabel}</div>
      ) : (
        <div className="space-y-3">
          {items.map((item: any) => (
            <div key={`${title}-${item.id}`} className="border rounded-lg p-4">
              <div className="font-medium">{item.name || item.code || `Record ${item.id}`}</div>
              <div className="text-sm text-muted-foreground">
                {item.description || item.type || item.currency_code || 'No additional details'}
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

export default function AccountingEntityManagementPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['accounting-entity-management'],
    queryFn: async () => {
      const [groups, entities, branches, books] = await Promise.all([
        api.get('/accounting/groups'),
        api.get('/accounting/legal-entities'),
        api.get('/accounting/branches'),
        api.get('/accounting/entity-books'),
      ]);

      return {
        groups: Array.isArray(groups.data) ? groups.data : (groups.data.data || []),
        entities: Array.isArray(entities.data) ? entities.data : (entities.data.data || []),
        branches: Array.isArray(branches.data) ? branches.data : (branches.data.data || []),
        books: Array.isArray(books.data) ? books.data : (books.data.data || []),
      };
    },
  });

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-3xl font-bold">Multi-Entity Management</h1>
        <p className="text-muted-foreground mt-1">
          Review legal entities, branches, books, and group structures from the accounting UI.
        </p>
      </div>

      {isLoading ? (
        <Card className="p-6 text-sm text-muted-foreground">Loading entity configuration...</Card>
      ) : (
        <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <EntitySection title="Groups" items={data?.groups || []} emptyLabel="No groups configured." />
          <EntitySection title="Legal Entities" items={data?.entities || []} emptyLabel="No legal entities configured." />
          <EntitySection title="Branches" items={data?.branches || []} emptyLabel="No branches configured." />
          <EntitySection title="Entity Books" items={data?.books || []} emptyLabel="No entity books configured." />
        </div>
      )}
    </div>
  );
}
