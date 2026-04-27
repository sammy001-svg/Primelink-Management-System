'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockInventory, mockProperties } from '@/lib/mock-data';
import { 
  Package, 
  Search, 
  Plus, 
  Filter, 
  MoreVertical,
  CheckCircle,
  AlertTriangle,
  Clock,
  Building2,
  X,
  Settings2,
  Wrench
} from 'lucide-react';

export default function InventoryPage() {
  const [searchTerm, setSearchTerm] = useState('');
  const [filterCategory, setFilterCategory] = useState<string>('All');
  const [showAddModal, setShowAddModal] = useState(false);

  // Categories
  const categories = ['All', 'Furniture', 'Appliance', 'Fixture', 'Other'];

  // Filtering
  const filteredInventory = mockInventory.filter(item => {
    const matchesSearch = item.name.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesCategory = filterCategory === 'All' || item.category === filterCategory;
    return matchesSearch && matchesCategory;
  });

  // Metrics
  const totalItems = mockInventory.length;
  const assignedItems = mockInventory.filter(i => i.status === 'Assigned').length;
  const maintenanceItems = mockInventory.filter(i => i.status === 'Maintenance').length;

  const statusColors: Record<string, string> = {
    'In Stock': 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    'Assigned': 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    'Maintenance': 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Inventory Management</h1>
            <p className="text-slate-500 mt-1 font-medium">Manage and track physical assets across properties.</p>
          </div>
          <button 
            onClick={() => setShowAddModal(true)}
            className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center gap-2"
          >
            <Plus size={18} /> Add Item
          </button>
        </div>

        {/* Metrics Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="glass-card p-6 border-l-4 border-l-blue-500">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-blue-500/10 rounded-2xl flex items-center justify-center text-blue-500">
                <Package size={24} />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Total Assets</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">{totalItems}</h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-green-500">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-green-500/10 rounded-2xl flex items-center justify-center text-green-500">
                <CheckCircle size={24} />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Assigned</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">{assignedItems}</h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-red-500">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-red-500/10 rounded-2xl flex items-center justify-center text-red-500">
                <Wrench size={24} />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">In Maintenance</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">{maintenanceItems}</h3>
              </div>
            </div>
          </div>
        </div>

        {/* Filters */}
        <div className="glass-card p-4 flex flex-col md:flex-row gap-4 items-center justify-between">
          <div className="relative w-full md:w-96">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={20} />
            <input 
              type="text" 
              placeholder="Search items..." 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium transition-all outline-none"
            />
          </div>
          <div className="flex gap-2 overflow-x-auto pb-2 md:pb-0 w-full md:w-auto">
            {categories.map(cat => (
              <button
                key={cat}
                onClick={() => setFilterCategory(cat)}
                className={`px-6 py-2 rounded-xl text-sm font-bold transition-all whitespace-nowrap ${
                  filterCategory === cat 
                    ? 'bg-slate-900 text-white dark:bg-slate-50 dark:text-slate-900' 
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400'
                }`}
              >
                {cat}
              </button>
            ))}
          </div>
        </div>

        {/* Inventory Table */}
        <div className="glass-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Item Details</th>
                  <th className="px-6 py-4">Category</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4">Assigned To</th>
                  <th className="px-6 py-4">Qty</th>
                  <th className="px-6 py-4">Condition</th>
                  <th className="px-6 py-4"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {filteredInventory.map((item) => {
                  const propertyName = mockProperties.find(p => p.id === item.assignedPropertyId)?.title || 'N/A';
                  
                  return (
                    <tr key={item.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-10 bg-slate-100 dark:bg-slate-800 rounded-xl flex items-center justify-center text-slate-500">
                            <Package size={20} />
                          </div>
                          <div>
                            <p className="font-bold text-slate-900 dark:text-white">{item.name}</p>
                            <p className="text-[10px] text-slate-500 font-mono">{item.id}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-sm font-semibold text-slate-600 dark:text-slate-400">{item.category}</td>
                      <td className="px-6 py-4">
                        <span className={`px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ${statusColors[item.status]}`}>
                          {item.status}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        {item.assignedPropertyId ? (
                          <div>
                            <p className="text-sm font-bold text-slate-700 dark:text-slate-300">{propertyName}</p>
                            <p className="text-[10px] text-slate-500 uppercase font-black">{item.assignedUnit}</p>
                          </div>
                        ) : (
                          <span className="text-slate-400 text-sm italic">Unassigned</span>
                        )}
                      </td>
                      <td className="px-6 py-4 font-black text-slate-900 dark:text-white">{item.quantity}</td>
                      <td className="px-6 py-4">
                        <span className={`text-[10px] font-black uppercase px-2 py-0.5 rounded border ${
                          item.condition === 'New' ? 'border-green-500 text-green-500' :
                          item.condition === 'Good' ? 'border-blue-500 text-blue-500' :
                          item.condition === 'Fair' ? 'border-orange-500 text-orange-500' :
                          'border-red-500 text-red-500'
                        }`}>
                          {item.condition}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button className="p-2 hover:bg-white dark:hover:bg-slate-800 rounded-xl text-slate-400 transition-all opacity-0 group-hover:opacity-100">
                          <MoreVertical size={18} />
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Add Item Modal (Mock) */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm">
          <div className="glass-card max-w-lg w-full p-8 relative">
            <button 
              onClick={() => setShowAddModal(false)}
              className="absolute top-6 right-6 p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-full hover:bg-secondary transition-colors"
            >
              <X size={20} />
            </button>
            <h2 className="text-2xl font-black text-slate-900 dark:text-white mb-6">Add Inventory Item</h2>
            <div className="space-y-4">
              <div className="form-group">
                <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Item Name</label>
                <input type="text" placeholder="e.g. Washing Machine" className="form-input" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="form-group">
                  <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Category</label>
                  <select className="form-input">
                    <option>Furniture</option>
                    <option>Appliance</option>
                    <option>Fixture</option>
                    <option>Other</option>
                  </select>
                </div>
                <div className="form-group">
                  <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Quantity</label>
                  <input type="number" defaultValue={1} className="form-input" />
                </div>
              </div>
              <div className="form-group">
                <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Purchase Date</label>
                <input type="date" className="form-input" />
              </div>
              <div className="pt-4 flex gap-4">
                <button 
                  onClick={() => setShowAddModal(false)}
                  className="w-full py-3 text-sm font-black uppercase tracking-widest text-slate-500"
                >
                  Discard
                </button>
                <button 
                  className="w-full py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-sm uppercase tracking-widest shadow-xl"
                >
                  Save Item
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
