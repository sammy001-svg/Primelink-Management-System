'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { 
  Users, 
  Shield, 
  ShieldCheck, 
  ShieldAlert,
  Search,
  Plus,
  MoreVertical,
  Mail,
  UserPlus,
  History,
  X,
  Check,
  Lock,
  User as UserIcon
} from 'lucide-react';

const mockUsers = [
  { id: 'u-1', name: 'Alexander Wright', email: 'alex@primelink.com', role: 'Admin', status: 'Active', lastActive: '2 mins ago' },
  { id: 'u-2', name: 'Sarah Miller', email: 'sarah@primelink.com', role: 'Manager', status: 'Active', lastActive: '1 day ago' },
  { id: 'u-3', name: 'John Doe', email: 'john@tenant.com', role: 'Tenant', status: 'Active', lastActive: '3 days ago' },
];

export default function UsersPage() {
  const [showAddModal, setShowAddModal] = useState(false);

  const roleIcons = {
    Admin: ShieldCheck,
    Manager: Shield,
    Tenant: Users,
  };

  const roleColors = {
    Admin: 'text-accent-gold bg-accent-gold/10',
    Manager: 'text-blue-500 bg-blue-500/10',
    Tenant: 'text-slate-500 bg-slate-500/10',
  };

  return (
    <DashboardLayout>
      <div className="space-y-8">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold text-slate-900 dark:text-white">User Management</h2>
            <p className="text-slate-500 text-sm">Manage system access and permissions (RBAC)</p>
          </div>
          <button 
            onClick={() => setShowAddModal(true)}
            className="flex items-center gap-2 px-6 py-2.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-bold hover:opacity-90 transition-opacity"
          >
            <UserPlus size={18} /> Add New User
          </button>
        </div>

        {/* ADD USER MODAL */}
        {showAddModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300">
            <div className="glass-card w-full max-w-xl bg-white dark:bg-slate-900 shadow-2xl overflow-hidden flex flex-col">
              <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50/50 dark:bg-slate-950/50">
                <h3 className="text-xl font-bold text-slate-900 dark:text-white">Create New Account</h3>
                <button 
                  onClick={() => setShowAddModal(false)}
                  className="p-2 transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white bg-white dark:bg-slate-800 rounded-xl shadow-sm"
                >
                  <X size={20} />
                </button>
              </div>

              <div className="p-8 space-y-6">
                <div className="form-group">
                  <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Full Name</label>
                  <div className="relative">
                    <UserIcon className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                    <input type="text" placeholder="Johnathan Doe" className="form-input pl-12" />
                  </div>
                </div>

                <div className="form-group">
                  <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Email Address</label>
                  <div className="relative">
                    <Mail className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                    <input type="email" placeholder="john@company.com" className="form-input pl-12" />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-6">
                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Access Level (Role)</label>
                    <select className="form-select font-bold">
                      <option>Admin</option>
                      <option>Manager</option>
                      <option>Tenant</option>
                    </select>
                  </div>
                  <div className="form-group">
                    <label className="form-label uppercase tracking-widest text-[10px] text-slate-400">Temporary Password</label>
                    <div className="relative">
                      <Lock className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                      <input type="password" placeholder="••••••••" className="form-input pl-12" />
                    </div>
                  </div>
                </div>
              </div>

              <div className="p-8 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/50 flex justify-end gap-4">
                <button 
                  onClick={() => setShowAddModal(false)}
                  className="px-8 py-3 text-sm font-black uppercase tracking-widest text-slate-500 hover:text-slate-900 transition-colors"
                >
                  Discard
                </button>
                <button 
                  className="px-10 py-3.5 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all flex items-center gap-2"
                >
                  <Check size={20} /> Provision User
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Roles Overview */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {[
            { role: 'Administrators', count: 3, description: 'Full system access', icon: ShieldCheck },
            { role: 'Property Managers', count: 8, description: 'Manage properties & tenants', icon: Shield },
            { role: 'Tenants', count: 124, description: 'Limited portal access', icon: Users },
          ].map((item, idx) => (
            <div key={idx} className="glass-card p-6 flex items-start gap-4">
              <div className="p-3 bg-slate-100 dark:bg-slate-800 rounded-xl text-slate-600 dark:text-slate-400">
                <item.icon size={24} />
              </div>
              <div>
                <h4 className="font-bold text-slate-900 dark:text-white">{item.role}</h4>
                <p className="text-2xl font-black my-1">{item.count}</p>
                <p className="text-xs text-slate-500 font-medium">{item.description}</p>
              </div>
            </div>
          ))}
        </div>

        {/* Users Table */}
        <div className="glass-card overflow-hidden">
          <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div className="relative w-full sm:w-96">
              <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
              <input 
                type="text" 
                placeholder="Search users..."
                className="w-full pl-12 pr-4 py-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900/50 focus:outline-none focus:ring-2 focus:ring-accent-gold/20 focus:border-accent-gold transition-all text-sm font-medium"
              />
            </div>
            <div className="flex gap-2 w-full sm:w-auto">
              <button className="flex-1 sm:flex-none px-4 py-2 border border-slate-200 dark:border-slate-800 rounded-xl text-xs font-bold hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors uppercase tracking-widest flex items-center justify-center gap-2">
                <History size={14} /> Audit Log
              </button>
            </div>
          </div>
          
          <div className="overflow-x-auto">
            <table className="w-full text-left">
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-900/50">
                  <th className="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">User</th>
                  <th className="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Role</th>
                  <th className="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                  <th className="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Last Active</th>
                  <th className="px-6 py-4"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {mockUsers.map((user) => {
                  const RoleIcon = roleIcons[user.role as keyof typeof roleIcons];
                  return (
                    <tr key={user.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div className="w-10 h-10 rounded-full bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 flex items-center justify-center font-bold text-sm">
                            {user.name.split(' ').map(n => n[0]).join('')}
                          </div>
                          <div>
                            <p className="font-bold text-slate-900 dark:text-white">{user.name}</p>
                            <div className="flex items-center gap-1 text-xs text-slate-500">
                              <Mail size={12} /> {user.email}
                            </div>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold ${roleColors[user.role as keyof typeof roleColors]}`}>
                          <RoleIcon size={14} />
                          {user.role}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-1.5">
                          <div className="w-1.5 h-1.5 rounded-full bg-green-500"></div>
                          <span className="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-widest">{user.status}</span>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-xs font-medium text-slate-500">
                        {user.lastActive}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button className="p-2 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">
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
    </DashboardLayout>
  );
}
