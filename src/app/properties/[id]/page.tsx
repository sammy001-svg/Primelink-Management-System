'use client';

import { useParams, useRouter } from 'next/navigation';
import DashboardLayout from '@/components/DashboardLayout';
import { mockProperties, mockTenants } from '@/lib/mock-data';
import { 
  ArrowLeft, MapPin, Building2, Tag, 
  Home, Users, DollarSign, Activity, Edit, Plus, User, Hash, FileText
} from 'lucide-react';
import Link from 'next/link';
import { useState } from 'react';

export default function PropertyDetailPage() {
  const params = useParams();
  const router = useRouter();
  const propertyId = params.id as string;
  
  const property = mockProperties.find(p => p.id === propertyId) || mockProperties[0];
  const propertyTenants = mockTenants.filter(t => t.propertyId === property.id);
  
  const [activeTab, setActiveTab] = useState<'overview' | 'units' | 'tenants'>('overview');

  if (!property) return <div className="p-8 text-center text-slate-500">Property not found</div>;

  const totalUnits = property.units.length;
  const occupiedUnits = property.units.filter(u => u.status === 'Occupied').length;
  const occupancyRate = totalUnits > 0 ? Math.round((occupiedUnits / totalUnits) * 100) : 0;
  
  // Financial mocks
  const monthlyExpected = property.price;
  const recentlyCollected = Math.floor(monthlyExpected * 0.8);
  const pendingCollection = monthlyExpected - recentlyCollected;

  const statusColors: Record<string, string> = {
    Available: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Rented: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    Maintenance: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Sold: 'bg-slate-100 text-slate-700 dark:bg-slate-900/30 dark:text-slate-400',
    Occupied: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
  };

  return (
    <DashboardLayout>
      <div className="space-y-6">
        {/* Header Actions */}
        <div className="flex items-center justify-between">
          <button 
            onClick={() => router.push('/properties')}
            className="flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors"
          >
            <ArrowLeft size={16} /> Back to Properties
          </button>
          <div className="flex gap-3">
            <button className="px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-sm font-bold hover:opacity-90 transition-opacity flex items-center gap-2">
              <Edit size={16} /> Edit Property
            </button>
          </div>
        </div>

        {/* Hero Section */}
        <div className="glass-card overflow-hidden">
          <div className="h-64 sm:h-80 w-full relative">
            <img 
              src={property.images[0] || 'https://images.unsplash.com/photo-1560518883-ce09059eeffa?q=80&w=1200'} 
              alt={property.title}
              className="w-full h-full object-cover"
            />
            <div className="absolute inset-0 bg-linear-to-t from-slate-950/90 via-slate-950/40 to-transparent"></div>
            
            <div className="absolute bottom-6 left-6 right-6 text-white">
              <div className="flex flex-wrap items-center gap-3 mb-3">
                <span className={`px-3 py-1 rounded-full text-xs font-bold backdrop-blur-md ${statusColors[property.status] || 'bg-white/20'}`}>
                  {property.status}
                </span>
                <span className="px-3 py-1 bg-accent-gold text-slate-900 rounded-full text-xs font-black uppercase tracking-wider shadow-lg">
                  For {property.listingType || 'Rent'}
                </span>
                <span className="flex items-center gap-1.5 px-3 py-1 bg-white/10 backdrop-blur-md rounded-full text-xs font-bold">
                  <Building2 size={12} /> {property.type}
                </span>
              </div>
              <h1 className="text-3xl sm:text-4xl md:text-5xl font-black mb-2">{property.title}</h1>
              <p className="flex items-center gap-2 text-slate-300 font-medium">
                <MapPin size={16} className="text-accent-gold" /> {property.location}
              </p>
            </div>
          </div>
          
          <div className="p-6 md:p-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-6 border-b border-slate-100 dark:border-slate-800">
             <div>
               <p className="text-sm font-bold text-slate-400 uppercase tracking-widest mb-1">
                 {property.listingType === 'Sale' ? 'Sales Value' : 'Monthly Rent'}
               </p>
               <p className="text-4xl font-black text-slate-900 dark:text-white">
                 KSh {property.price.toLocaleString()}
               </p>
             </div>
             
             {/* Key Metrics Grid inline */}
             <div className="grid grid-cols-2 md:grid-cols-4 gap-4 w-full md:w-auto text-center md:text-left">
                <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800">
                  <Building2 size={24} className="text-accent-gold mb-2 mx-auto md:mx-0" />
                  <p className="text-2xl font-black text-slate-900 dark:text-white">{totalUnits}</p>
                  <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Total Units</p>
                </div>
                <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800">
                  <Activity size={24} className="text-blue-500 mb-2 mx-auto md:mx-0" />
                  <p className="text-2xl font-black text-slate-900 dark:text-white">{occupancyRate}%</p>
                  <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Occupancy</p>
                </div>
                <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800">
                  <DollarSign size={24} className="text-green-500 mb-2 mx-auto md:mx-0" />
                  <p className="text-xl font-black text-slate-900 dark:text-white">{(recentlyCollected/1000).toFixed(1)}k</p>
                  <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Collected</p>
                </div>
                <div className="p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl border border-slate-100 dark:border-slate-800">
                  <FileText size={24} className="text-orange-500 mb-2 mx-auto md:mx-0" />
                  <p className="text-xl font-black text-slate-900 dark:text-white">{(pendingCollection/1000).toFixed(1)}k</p>
                  <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest mt-1">Pending</p>
                </div>
             </div>
          </div>
        </div>

        {/* Tab Navigation */}
        <div className="flex border-b border-slate-200 dark:border-slate-800">
          {[
            { id: 'overview', label: 'Overview', icon: Home },
            { id: 'units', label: `Units (${totalUnits})`, icon: Hash },
            { id: 'tenants', label: `Tenants (${propertyTenants.length})`, icon: Users },
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id as any)}
              className={`flex items-center gap-2 px-6 py-4 text-sm font-bold border-b-2 transition-colors ${
                activeTab === tab.id 
                  ? 'border-accent-gold text-accent-gold' 
                  : 'border-transparent text-slate-500 hover:text-slate-900 dark:hover:text-white'
              }`}
            >
              <tab.icon size={16} /> {tab.label}
            </button>
          ))}
        </div>

        <div className="animate-in fade-in slide-in-from-bottom-2 duration-300">
          {activeTab === 'overview' && (
            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
              <div className="lg:col-span-2 space-y-6">
                <div className="glass-card p-6 md:p-8">
                  <h3 className="text-lg font-black mb-4 flex items-center gap-2">
                    <Tag className="text-accent-gold" size={20} /> About This Property
                  </h3>
                  <p className="text-slate-600 dark:text-slate-400 leading-relaxed">
                    {property.description}
                  </p>
                  
                  <div className="mt-8">
                    <h4 className="text-sm font-bold text-slate-900 dark:text-white mb-4">Amenities & Features</h4>
                    <div className="flex flex-wrap gap-2">
                      {property.amenities.map((amenity, i) => (
                        <span key={i} className="px-4 py-2 bg-slate-50 dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-semibold text-slate-700 dark:text-slate-300">
                          {amenity}
                        </span>
                      ))}
                    </div>
                  </div>
                </div>
              </div>
              
              <div className="space-y-6">
                <div className="glass-card p-6">
                  <h3 className="text-lg font-black mb-6">Property Details</h3>
                  <div className="space-y-4">
                    <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                      <span className="text-sm font-bold text-slate-500">Property ID</span>
                      <span className="text-sm font-black font-mono text-slate-900 dark:text-white">{property.id}</span>
                    </div>
                    <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                      <span className="text-sm font-bold text-slate-500">Category</span>
                      <span className="text-sm font-black text-slate-900 dark:text-white">{property.type}</span>
                    </div>
                    <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                      <span className="text-sm font-bold text-slate-500">Total Area</span>
                      <span className="text-sm font-black text-slate-900 dark:text-white">{property.area} sqft</span>
                    </div>
                    {property.bedrooms && (
                      <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                        <span className="text-sm font-bold text-slate-500">Bedrooms</span>
                        <span className="text-sm font-black text-slate-900 dark:text-white">{property.bedrooms}</span>
                      </div>
                    )}
                    {property.bathrooms && (
                      <div className="flex justify-between py-3 border-b border-slate-100 dark:border-slate-800">
                        <span className="text-sm font-bold text-slate-500">Bathrooms</span>
                        <span className="text-sm font-black text-slate-900 dark:text-white">{property.bathrooms}</span>
                      </div>
                    )}
                  </div>
                </div>
              </div>
            </div>
          )}

          {activeTab === 'units' && (
            <div className="glass-card p-0 overflow-hidden">
              <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                <h3 className="text-lg font-black">Unit Configuration</h3>
                <button className="px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-sm font-bold flex items-center gap-2 hover:opacity-90">
                  <Plus size={16} /> Add Unit
                </button>
              </div>
              
              {property.units.length > 0 ? (
                <div className="overflow-x-auto custom-scrollbar">
                  <table className="w-full text-left border-collapse min-w-[600px]">
                    <thead>
                      <tr className="bg-slate-50/50 dark:bg-slate-900/50 border-b border-slate-100 dark:border-slate-800 text-xs uppercase tracking-widest text-slate-500 font-bold">
                        <th className="p-4 pl-6">Unit #</th>
                        <th className="p-4">Type</th>
                        <th className="p-4">Status</th>
                        <th className="p-4">Rent/Mo</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {property.units.map(unit => (
                        <tr key={unit.id} className="hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                          <td className="p-4 pl-6 font-bold text-slate-900 dark:text-white">
                            <div className="flex items-center gap-3">
                              <div className="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-accent-gold">
                                <Hash size={14} />
                              </div>
                              {unit.number}
                            </div>
                          </td>
                          <td className="p-4 font-semibold text-slate-500">{unit.type}</td>
                          <td className="p-4">
                            <span className={`px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest ${statusColors[unit.status] || 'bg-slate-100 text-slate-700'}`}>
                              {unit.status}
                            </span>
                          </td>
                          <td className="p-4 font-black">KSh {unit.rent?.toLocaleString() || 'N/A'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="p-12 text-center">
                  <Building2 size={48} className="mx-auto text-slate-300 dark:text-slate-700 mb-4" />
                  <p className="text-lg font-bold text-slate-900 dark:text-white mb-2">No units configured</p>
                  <p className="text-slate-500 mb-6">This property is tracked as a single asset without individual rentable units.</p>
                </div>
              )}
            </div>
          )}

          {activeTab === 'tenants' && (
            <div className="glass-card p-0 overflow-hidden">
               {propertyTenants.length > 0 ? (
                <div className="overflow-x-auto custom-scrollbar">
                  <table className="w-full text-left border-collapse min-w-[800px]">
                    <thead>
                      <tr className="bg-slate-50/50 dark:bg-slate-900/50 border-b border-slate-100 dark:border-slate-800 text-xs uppercase tracking-widest text-slate-500 font-bold">
                        <th className="p-4 pl-6">Tenant Name</th>
                        <th className="p-4">Unit</th>
                        <th className="p-4">Status</th>
                        <th className="p-4">Lease Ends</th>
                        <th className="p-4">Rent/Mo</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                      {propertyTenants.map(tenant => (
                        <tr 
                           key={tenant.id} 
                           onClick={() => router.push(`/tenants/${tenant.id}`)}
                           className="hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors cursor-pointer group"
                        >
                          <td className="p-4 pl-6">
                            <div className="flex items-center gap-3">
                              <img src={tenant.profileImage || `https://ui-avatars.com/api/?name=${tenant.name}&background=random`} alt={tenant.name} className="w-10 h-10 rounded-full object-cover border-2 border-slate-100 dark:border-slate-800" />
                              <div>
                                <p className="font-bold text-slate-900 dark:text-white group-hover:text-accent-gold transition-colors">{tenant.name}</p>
                                <p className="text-xs text-slate-500">{tenant.phone}</p>
                              </div>
                            </div>
                          </td>
                          <td className="p-4 font-semibold text-slate-500">{tenant.unit || 'N/A'}</td>
                          <td className="p-4">
                            <span className={`px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-widest ${
                              tenant.status === 'Active' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' :
                              tenant.status === 'Pending' ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' :
                              'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                            }`}>
                              {tenant.status}
                            </span>
                          </td>
                          <td className="p-4 font-semibold text-slate-500">{tenant.leaseEnd}</td>
                          <td className="p-4 font-black">KSh {tenant.rentAmount.toLocaleString()}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div className="p-12 text-center text-slate-500">
                  <Users size={48} className="mx-auto text-slate-300 dark:text-slate-700 mb-4" />
                  <p className="text-lg font-bold text-slate-900 dark:text-white mb-2">No active tenants</p>
                  <p className="text-slate-500">There are no tenants currently assigned to this property.</p>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </DashboardLayout>
  );
}
