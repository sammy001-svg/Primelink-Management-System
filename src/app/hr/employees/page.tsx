'use client';

import { useState } from 'react';
import DashboardLayout from '@/components/DashboardLayout';
import { mockEmployees as initialEmployees, Employee } from '@/lib/mock-data';
import { 
  Users, 
  Search, 
  Plus, 
  Filter, 
  MoreVertical,
  CheckCircle,
  Clock,
  X,
  Mail,
  Phone,
  Briefcase,
  Calendar,
  Key,
  Hash
} from 'lucide-react';
import { useRouter } from 'next/navigation';

export default function EmployeesPage() {
  const router = useRouter();
  const [employees, setEmployees] = useState<Employee[]>(initialEmployees);
  const [searchTerm, setSearchTerm] = useState('');
  const [filterDepartment, setFilterDepartment] = useState<string>('All');
  const [showAddModal, setShowAddModal] = useState(false);
  
  // Form State
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    role: '',
    department: 'Administration' as Employee['department'],
    salary: '',
    password: ''
  });

  // Generate Employee Number
  const generateEmployeeNumber = () => {
    const nextId = employees.length + 1;
    return `PL-EMP-${nextId.toString().padStart(3, '0')}`;
  };

  const handleAddEmployee = (e: React.FormEvent) => {
    e.preventDefault();
    const newEmployee: Employee = {
      id: `emp-${employees.length + 1}`,
      employeeNumber: generateEmployeeNumber(),
      name: formData.name,
      email: formData.email,
      phone: formData.phone,
      role: formData.role,
      department: formData.department,
      status: 'Active',
      joinDate: new Date().toISOString().split('T')[0],
      salary: Number(formData.salary),
      password: formData.password,
    };

    setEmployees([...employees, newEmployee]);
    setShowAddModal(false);
    setFormData({
      name: '',
      email: '',
      phone: '',
      role: '',
      department: 'Administration',
      salary: '',
      password: ''
    });
  };

  // Filtering
  const filteredEmployees = employees.filter(employee => {
    const matchesSearch = employee.name.toLowerCase().includes(searchTerm.toLowerCase()) || 
                          employee.role.toLowerCase().includes(searchTerm.toLowerCase()) ||
                          employee.employeeNumber.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesDepartment = filterDepartment === 'All' || employee.department === filterDepartment;
    
    return matchesSearch && matchesDepartment;
  });

  const departmentColors: Record<string, string> = {
    Administration: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    Maintenance: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Finance: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Security: 'bg-slate-100 text-slate-700 dark:bg-slate-900/30 dark:text-slate-400',
    Sales: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
  };

  const statusColors: Record<string, string> = {
    Active: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    'On Leave': 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Terminated: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
  };

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Content */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Employee Management</h1>
            <p className="text-slate-500 mt-1 font-medium">Manage your workforce, roles, and departments.</p>
          </div>
          <button 
            onClick={() => setShowAddModal(true)}
            className="px-6 py-3 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] hover:shadow-accent-gold/20 active:translate-y-0 transition-all flex items-center gap-2"
          >
            <Plus size={18} /> Add Employee
          </button>
        </div>

        {/* Metrics Grid */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div className="glass-card p-6 border-l-4 border-l-blue-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-blue-500/10 rounded-2xl flex items-center justify-center text-blue-500">
                <Users size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Total Employees</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">{employees.length}</h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-green-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-green-500/10 rounded-2xl flex items-center justify-center text-green-500">
                <CheckCircle size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">Active Now</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {employees.filter(e => e.status === 'Active').length}
                </h3>
              </div>
            </div>
          </div>

          <div className="glass-card p-6 border-l-4 border-l-orange-500 hover:shadow-xl transition-all hover:scale-[1.02]">
            <div className="flex items-center gap-4">
              <div className="w-12 h-12 bg-orange-500/10 rounded-2xl flex items-center justify-center text-orange-500">
                <Clock size={24} className="opacity-80" />
              </div>
              <div>
                <p className="text-sm font-bold text-slate-400 uppercase tracking-widest">On Leave</p>
                <h3 className="text-3xl font-black text-slate-900 dark:text-white">
                  {employees.filter(e => e.status === 'On Leave').length}
                </h3>
              </div>
            </div>
          </div>
        </div>

        {/* Filters Box */}
        <div className="glass-card p-4 flex flex-col md:flex-row gap-4 items-center justify-between">
          <div className="relative w-full md:w-96 group">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={20} />
            <input 
              type="text" 
              placeholder="Search by name, role or emp #" 
              value={searchTerm}
              onChange={(e) => setSearchTerm(e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold dark:focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium transition-all outline-none"
            />
          </div>
          <div className="flex gap-4 w-full md:w-auto overflow-x-auto custom-scrollbar pb-2 md:pb-0">
            {['All', 'Administration', 'Maintenance', 'Finance', 'Security', 'Sales'].map(dept => (
              <button
                key={dept}
                onClick={() => setFilterDepartment(dept)}
                className={`px-6 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-all ${
                  filterDepartment === dept 
                    ? 'bg-slate-900 text-white shadow-lg dark:bg-slate-50 dark:text-slate-900' 
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:hover:bg-slate-700'
                }`}
              >
                {dept}
              </button>
            ))}
          </div>
        </div>

        {/* Employees Data Table */}
        <div className="glass-card overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left border-collapse min-w-[800px]">
              <thead>
                <tr className="bg-slate-50/50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 uppercase text-[10px] font-black tracking-widest border-b border-slate-100 dark:border-slate-800">
                  <th className="px-6 py-4">Employee info</th>
                  <th className="px-6 py-4">Emp #</th>
                  <th className="px-6 py-4">Role & Dept</th>
                  <th className="px-6 py-4">Contact</th>
                  <th className="px-6 py-4">Join Date</th>
                  <th className="px-6 py-4">Status</th>
                  <th className="px-6 py-4"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {filteredEmployees.length > 0 ? (
                  filteredEmployees.map((employee) => (
                    <tr 
                      key={employee.id} 
                      className="hover:bg-slate-50/50 dark:hover:bg-slate-900/50 transition-colors group cursor-pointer"
                    >
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <img 
                            src={employee.profileImage || `https://ui-avatars.com/api/?name=${employee.name}&background=random`} 
                            alt={employee.name} 
                            className="w-10 h-10 rounded-full object-cover border-2 border-white dark:border-slate-800 shadow-sm"
                          />
                          <p className="font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{employee.name}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="text-xs font-black bg-slate-100 dark:bg-slate-800 px-3 py-1 rounded-lg text-slate-600 dark:text-slate-400 tracking-widest">
                          {employee.employeeNumber}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <div className="space-y-1">
                          <div className="flex items-center gap-2 text-slate-700 dark:text-slate-300">
                            <Briefcase size={14} className="text-slate-400" />
                            <p className="font-bold text-sm">{employee.role}</p>
                          </div>
                          <span className={`px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-tighter ${departmentColors[employee.department]}`}>
                            {employee.department}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="space-y-1 text-sm">
                          <div className="flex items-center gap-2 text-slate-600 dark:text-slate-400">
                            <Mail size={14} />
                            <span>{employee.email}</span>
                          </div>
                          <div className="flex items-center gap-2 text-slate-600 dark:text-slate-400">
                            <Phone size={14} />
                            <span>{employee.phone}</span>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 text-sm text-slate-600 dark:text-slate-400 font-semibold">
                        <div className="flex items-center gap-2">
                          <Calendar size={14} className="text-slate-400" />
                          {new Date(employee.joinDate).toLocaleDateString()}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-sm bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                          {employee.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button className="p-2 hover:bg-white dark:hover:bg-slate-800 rounded-xl text-slate-400 hover:text-accent-gold transition-all shadow-sm opacity-0 group-hover:opacity-100">
                          <MoreVertical size={18} />
                        </button>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan={7} className="px-6 py-12 text-center text-slate-500 bg-slate-50/50 dark:bg-slate-900/10">
                      <Users size={48} className="mx-auto text-slate-300 dark:text-slate-700 mb-4" />
                      <p className="text-lg font-bold text-slate-900 dark:text-white mb-2">No employees found</p>
                      <p className="text-slate-500">We couldn't find any employees matching your current criteria.</p>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Add Employee Modal */}
      {showAddModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/40 backdrop-blur-sm animate-in fade-in duration-300 overflow-y-auto">
          <div className="glass-card max-w-2xl w-full p-8 relative my-8">
            <button 
              onClick={() => setShowAddModal(false)}
              className="absolute top-6 right-6 p-2 bg-slate-100 dark:bg-slate-800 text-slate-500 rounded-full hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors"
            >
              <X size={20} />
            </button>
            <div className="flex items-center gap-4 mb-8">
              <div className="w-12 h-12 bg-accent-gold/10 text-accent-gold rounded-xl flex items-center justify-center">
                <Plus size={24} />
              </div>
              <div>
                <h2 className="text-2xl font-black text-slate-900 dark:text-white">Add New Employee</h2>
                <p className="text-slate-500 text-sm font-medium">Auto-generating Emp #: <span className="text-accent-gold font-bold">{generateEmployeeNumber()}</span></p>
              </div>
            </div>

            <form onSubmit={handleAddEmployee} className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Full Name</label>
                  <input 
                    required
                    type="text" 
                    value={formData.name}
                    onChange={(e) => setFormData({...formData, name: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-medium outline-none transition-all"
                    placeholder="Enter full name"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Email Address</label>
                  <input 
                    required
                    type="email" 
                    value={formData.email}
                    onChange={(e) => setFormData({...formData, email: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-medium outline-none transition-all"
                    placeholder="email@primelink.com"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Phone Number</label>
                  <input 
                    required
                    type="tel" 
                    value={formData.phone}
                    onChange={(e) => setFormData({...formData, phone: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-medium outline-none transition-all"
                    placeholder="+254..."
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Department</label>
                  <select 
                    value={formData.department}
                    onChange={(e) => setFormData({...formData, department: e.target.value as any})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-medium outline-none transition-all"
                  >
                    <option>Administration</option>
                    <option>Maintenance</option>
                    <option>Finance</option>
                    <option>Security</option>
                    <option>Sales</option>
                  </select>
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Job Role</label>
                  <input 
                    required
                    type="text" 
                    value={formData.role}
                    onChange={(e) => setFormData({...formData, role: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-medium outline-none transition-all"
                    placeholder="e.g. Property Manager"
                  />
                </div>
                <div className="space-y-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Monthly Salary (KSh)</label>
                  <input 
                    required
                    type="number" 
                    value={formData.salary}
                    onChange={(e) => setFormData({...formData, salary: e.target.value})}
                    className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl px-4 py-3 text-sm font-medium outline-none transition-all"
                    placeholder="50000"
                  />
                </div>
                <div className="space-y-2 md:col-span-2">
                  <label className="text-xs font-black uppercase tracking-widest text-slate-400">Portal Password</label>
                  <div className="relative">
                    <Key className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                    <input 
                      required
                      type="password" 
                      value={formData.password}
                      onChange={(e) => setFormData({...formData, password: e.target.value})}
                      className="w-full bg-slate-50 dark:bg-slate-900 border-2 border-transparent focus:border-accent-gold rounded-xl pl-12 pr-4 py-3 text-sm font-medium outline-none transition-all"
                      placeholder="Set initial password"
                    />
                  </div>
                </div>
              </div>

              <div className="pt-6 flex gap-4">
                <button 
                  type="button"
                  onClick={() => setShowAddModal(false)}
                  className="flex-1 px-6 py-4 font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
                >
                  Cancel
                </button>
                <button 
                  type="submit"
                  className="flex-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] transition-all"
                >
                  Onboard Employee
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </DashboardLayout>
  );
}
