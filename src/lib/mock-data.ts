export interface Property {
  id: string;
  title: string;
  description: string;
  price: number;
  listingType?: 'Rent' | 'Sale';
  location: string;
  type: 'Apartment' | 'Villa' | 'Single Room' | 'Shop' | 'Office' | 'Land' | 'Other' | 'Residential' | 'Commercial' | 'Industrial';
  status: 'Available' | 'Rented' | 'Maintenance' | 'Sold';
  unitNo?: string;
  floorNumber?: string | number;
  unitType?: string;
  images: string[];
  amenities: string[];
  bedrooms?: number;
  bathrooms?: number;
  area: number; // in sqft or sqm
  units: Unit[];
}

export interface Unit {
  id: string;
  number: string;
  type: 'Studio' | '1BR' | '2BR' | '3BR' | 'Penthouse' | 'Office' | 'Retail';
  status: 'Available' | 'Occupied' | 'Maintenance' | 'Reserved';
  rent?: number;
}

export interface Tenant {
  id: string;
  name: string;
  email: string;
  phone: string;
  nationalId?: string;
  propertyId: string; // Linked property
  propertyName: string;
  unit?: string;
  leaseStart: string;
  leaseEnd: string;
  rentAmount: number;
  pendingBalance: number;
  status: 'Active' | 'Pending' | 'Terminated';
  profileImage?: string;
}

export interface MaintenanceRequest {
  id: string;
  propertyId: string;
  propertyName: string;
  tenantId: string;
  tenantName: string;
  issue: string;
  description: string;
  priority: 'Low' | 'Medium' | 'High' | 'Emergency';
  status: 'Open' | 'In Progress' | 'Resolved' | 'Cancelled';
  dateSubmitted: string;
}
export interface InventoryItem {
  id: string;
  name: string;
  category: 'Furniture' | 'Appliance' | 'Fixture' | 'Other';
  status: 'In Stock' | 'Assigned' | 'Maintenance';
  quantity: number;
  condition: 'New' | 'Good' | 'Fair' | 'Poor';
  purchaseDate: string;
  assignedPropertyId?: string;
  assignedUnit?: string;
}

export interface TokenTransaction {
  id: string;
  tenantId: string;
  tenantName: string;
  propertyId: string;
  propertyName: string;
  unit: string;
  type: 'Electricity' | 'Water';
  amount: number;
  units: number; // kWh or m3
  tokenCode: string;
  date: string;
  status: 'Success' | 'Pending' | 'Failed';
}

export interface Landlord {
  id: string;
  name: string;
  email: string;
  phone: string;
  propertyIds: string[]; // Properties they own
  payoutAccount: {
    bankName: string;
    accountNumber: string;
    accountName: string;
  };
  totalEarned: number;
  pendingPayout: number;
  advancePaid: number;
  profileImage?: string;
  payouts: LandlordPayout[];
}

export interface LandlordPayout {
  id: string;
  date: string;
  amount: number;
  type: 'Rent Payout' | 'Advance Payment';
  status: 'Completed' | 'Pending' | 'Failed';
  reference: string;
  period: string;
  breakdown: {
    grossCollection: number;
    managementFee: number;
    tax: number;
    netPayout: number;
  };
}

export interface Vendor {
  id: string;
  name: string;
  category: 'Plumbing' | 'Electrical' | 'HVAC' | 'General' | 'Security';
  contact: string;
  phone: string;
  rating: number;
}

export const mockProperties: Property[] = [
  {
    id: 'prop-1',
    title: 'Elysian Heights Luxury Villa',
    description: 'A stunning modern villa located in the heart of the Golden District, featuring panoramic city views and premium finishes.',
    price: 4500,
    location: 'Golden District, City Center',
    type: 'Villa',
    status: 'Available',
    images: ['https://images.unsplash.com/photo-1613490493576-7fde63acd811?q=80&w=800'],
    amenities: ['Pool', 'Gym', 'Smart Home', '24/7 Security'],
    bedrooms: 4,
    bathrooms: 3.5,
    area: 3200,
    units: [
      { id: 'u-1-1', number: '101', type: '3BR', status: 'Occupied', rent: 4500 },
      { id: 'u-1-2', number: '102', type: '3BR', status: 'Available', rent: 4500 },
      { id: 'u-1-3', number: '201', type: 'Penthouse', status: 'Maintenance', rent: 8500 }
    ]
  },
  {
    id: 'prop-2',
    title: 'Skyline Business Hub',
    description: 'State-of-the-art office space with flexible layout options and high-speed fiber connectivity.',
    price: 8200,
    location: 'Financial District',
    type: 'Office',
    status: 'Rented',
    images: ['https://images.unsplash.com/photo-1497366216548-37526070297c?q=80&w=800'],
    amenities: ['Fiber Internet', 'Conference Rooms', 'Cafeteria', 'Parking'],
    area: 5500,
    units: [
      { id: 'u-2-1', number: 'Floor 1', type: 'Office', status: 'Occupied', rent: 8200 },
      { id: 'u-2-2', number: 'Floor 2', type: 'Office', status: 'Available', rent: 8200 }
    ]
  },
  {
    id: 'prop-3',
    title: 'Azure Bay Apartment',
    description: 'Beautiful waterfront apartment with floor-to-ceiling windows and private balcony.',
    price: 2800,
    location: 'Azure Bay Waterfront',
    type: 'Apartment',
    status: 'Available',
    images: ['https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?q=80&w=800'],
    amenities: ['Water View', 'Beach Access', 'Concierge'],
    bedrooms: 2,
    bathrooms: 2,
    area: 1200,
    units: [
      { id: 'u-3-1', number: 'A-01', type: '2BR', status: 'Available', rent: 2800 },
      { id: 'u-3-2', number: 'A-02', type: '2BR', status: 'Available', rent: 2800 }
    ]
  }
];

export const mockTenants: Tenant[] = [
  {
    id: 't-1',
    name: 'Johnathan Davis',
    email: 'john.davis@example.com',
    phone: '+1 (555) 123-4567',
    nationalId: 'ID-87654321',
    propertyId: 'prop-1',
    propertyName: 'Elysian Heights Luxury Villa',
    unit: '101',
    leaseStart: '2023-01-15',
    leaseEnd: '2024-01-14',
    rentAmount: 4500,
    pendingBalance: 4500,
    status: 'Active',
    profileImage: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=200'
  },
  {
    id: 't-2',
    name: 'Sarah Wilson',
    email: 's.wilson@corp.com',
    phone: '+1 (555) 987-6543',
    nationalId: 'ID-23456789',
    propertyId: 'prop-2',
    propertyName: 'Skyline Business Hub',
    unit: 'Floor 1',
    leaseStart: '2023-06-01',
    leaseEnd: '2026-05-31',
    rentAmount: 8200,
    pendingBalance: 0,
    status: 'Active',
    profileImage: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?q=80&w=200'
  },
  {
    id: 't-3',
    name: 'Michael Chen',
    email: 'm.chen@outlook.com',
    phone: '+1 (555) 456-7890',
    nationalId: 'ID-34567890',
    propertyId: '',
    propertyName: 'Waiting List',
    unit: 'N/A',
    leaseStart: '2024-04-01',
    leaseEnd: '2025-03-31',
    rentAmount: 3000,
    pendingBalance: 3000,
    status: 'Pending',
    profileImage: 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?q=80&w=200'
  }
];

export const mockMaintenanceRequests: MaintenanceRequest[] = [
  {
    id: 'mr-1',
    propertyId: 'prop-1',
    propertyName: 'Elysian Heights Luxury Villa',
    tenantId: 't-1',
    tenantName: 'Johnathan Davis',
    issue: 'HVAC Malfunction',
    description: 'The air conditioning unit in the master bedroom is making a loud rattling noise and not cooling.',
    priority: 'High',
    status: 'In Progress',
    dateSubmitted: '2024-03-08'
  },
  {
    id: 'mr-2',
    propertyId: 'prop-3',
    propertyName: 'Azure Bay Apartment',
    tenantId: 't-3',
    tenantName: 'Michael Chen',
    issue: 'Leaking Faucet',
    description: 'Kitchen faucet is dripping constantly, causing water waste.',
    priority: 'Low',
    status: 'Open',
    dateSubmitted: '2024-03-10'
  }
];

export const mockVendors: Vendor[] = [
  { id: 'v-1', name: 'Swift Plumbing Co.', category: 'Plumbing', contact: 'Mark Evans', phone: '+1 (555) 012-3456', rating: 4.8 },
  { id: 'v-2', name: 'Sparky Electrical', category: 'Electrical', contact: 'Jane Smith', phone: '+1 (555) 012-7890', rating: 4.5 },
  { id: 'v-3', name: 'IceCold HVAC', category: 'HVAC', contact: 'Tom Baker', phone: '+1 (555) 012-1234', rating: 4.9 }
];

export interface PropertyDocument {
  id: string;
  name: string;
  type: 'PDF' | 'DOC' | 'Image';
  category: 'Lease' | 'Insurance' | 'Maintenance' | 'Other';
  propertyId: string;
  propertyName: string;
  dateUploaded: string;
  size: string;
}

export const mockDocuments: PropertyDocument[] = [
  { id: 'doc-1', name: 'Lease_Agreement_Elysian.pdf', type: 'PDF', category: 'Lease', propertyId: 'prop-1', propertyName: 'Elysian Heights Luxury Villa', dateUploaded: '2024-01-15', size: '2.4 MB' },
  { id: 'doc-2', name: 'Insurance_Policy_2024.pdf', type: 'PDF', category: 'Insurance', propertyId: 'prop-2', propertyName: 'Skyline Terrace Penthouse', dateUploaded: '2024-01-20', size: '1.8 MB' },
  { id: 'doc-3', name: 'Property_Inspection_Report.pdf', type: 'PDF', category: 'Maintenance', propertyId: 'prop-3', propertyName: 'Azure Bay Apartment', dateUploaded: '2024-02-05', size: '3.1 MB' },
  { id: 'doc-4', name: 'Maintenance_Receipt_Plumbing.pdf', type: 'PDF', category: 'Maintenance', propertyId: 'prop-1', propertyName: 'Elysian Heights Luxury Villa', dateUploaded: '2024-03-01', size: '0.5 MB' },
];

export const mockOccupancyData = [
  { month: 'Jan', rate: 85 },
  { month: 'Feb', rate: 88 },
  { month: 'Mar', rate: 92 },
  { month: 'Apr', rate: 90 },
  { month: 'May', rate: 94 },
  { month: 'Jun', rate: 96 },
];

export const mockRevenueData = [
  { month: 'Jan', amount: 45000 },
  { month: 'Feb', amount: 48000 },
  { month: 'Mar', amount: 52000 },
  { month: 'Apr', amount: 51000 },
  { month: 'May', amount: 55000 },
  { month: 'Jun', amount: 58000 },
];

export const mockInventory: InventoryItem[] = [
  {
    id: 'inv-1',
    name: 'Samsung Smart Fridge',
    category: 'Appliance',
    status: 'Assigned',
    quantity: 1,
    condition: 'New',
    purchaseDate: '2023-10-12',
    assignedPropertyId: 'prop-1',
    assignedUnit: 'Unit 101'
  },
  {
    id: 'inv-2',
    name: 'Leather Sofa Set',
    category: 'Furniture',
    status: 'Assigned',
    quantity: 1,
    condition: 'Good',
    purchaseDate: '2023-08-05',
    assignedPropertyId: 'prop-2',
    assignedUnit: 'Unit A'
  },
  {
    id: 'inv-3',
    name: 'Microwave Oven',
    category: 'Appliance',
    status: 'In Stock',
    quantity: 5,
    condition: 'New',
    purchaseDate: '2024-01-20'
  },
  {
    id: 'inv-4',
    name: 'Office Desk & Chair',
    category: 'Furniture',
    status: 'Maintenance',
    quantity: 2,
    condition: 'Fair',
    purchaseDate: '2022-11-15'
  },
  {
    id: 'inv-5',
    name: 'LED Ceiling Light',
    category: 'Fixture',
    status: 'In Stock',
    quantity: 25,
    condition: 'New',
    purchaseDate: '2024-02-10'
  }
];

export const mockTokenTransactions: TokenTransaction[] = [
  {
    id: 'tok-1001',
    tenantId: 't-1',
    tenantName: 'Sarah Johnson',
    propertyId: 'prop-1',
    propertyName: 'Elysian Heights Luxury Villa',
    unit: 'Unit 101',
    type: 'Electricity',
    amount: 2500,
    units: 113.6,
    tokenCode: '4582 9102 3341 0092 1128',
    date: '2024-03-10T14:30:00Z',
    status: 'Success'
  },
  {
    id: 'tok-1002',
    tenantId: 't-2',
    tenantName: 'David Kamau',
    propertyId: 'prop-2',
    propertyName: 'Skyline Terrace Penthouse',
    unit: 'Penthouse B',
    type: 'Water',
    amount: 1200,
    units: 15.4,
    tokenCode: '9901 2283 4410 2291',
    date: '2024-03-11T09:15:00Z',
    status: 'Success'
  },
  {
    id: 'tok-1003',
    tenantId: 't-3',
    tenantName: 'Michael Chen',
    propertyId: 'prop-3',
    propertyName: 'Azure Bay Apartment',
    unit: 'Unit 402',
    type: 'Electricity',
    amount: 5000,
    units: 227.2,
    tokenCode: '1102 3349 8812 0031 4452',
    date: '2024-03-08T18:45:00Z',
    status: 'Success'
  }
];

export const mockLandlords: Landlord[] = [
  {
    id: 'l-1',
    name: 'Robert Mulwa',
    email: 'robert.mulwa@example.com',
    phone: '+254 712 345 678',
    propertyIds: ['prop-1', 'prop-3'],
    payoutAccount: {
      bankName: 'Equity Bank',
      accountNumber: '1234567890',
      accountName: 'Robert Mulwa Estates'
    },
    totalEarned: 1250000,
    pendingPayout: 45000,
    advancePaid: 0,
    profileImage: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=200',
    payouts: [
      {
        id: 'pay-1',
        date: '2024-03-01',
        amount: 145000,
        type: 'Rent Payout',
        status: 'Completed',
        reference: 'PAY-99021',
        period: 'Feb 2024',
        breakdown: {
          grossCollection: 165000,
          managementFee: 16500,
          tax: 3500,
          netPayout: 145000
        }
      },
      {
        id: 'pay-2',
        date: '2024-02-01',
        amount: 142000,
        type: 'Rent Payout',
        status: 'Completed',
        reference: 'PAY-98772',
        period: 'Jan 2024',
        breakdown: {
          grossCollection: 160000,
          managementFee: 16000,
          tax: 2000,
          netPayout: 142000
        }
      }
    ]
  },
  {
    id: 'l-2',
    name: 'Alice Wambui',
    email: 'alice.w.property@gmail.com',
    phone: '+254 722 987 654',
    propertyIds: ['prop-2'],
    payoutAccount: {
      bankName: 'KCB Bank',
      accountNumber: '9876543210',
      accountName: 'Alice Wambui'
    },
    totalEarned: 850000,
    pendingPayout: 12000,
    advancePaid: 50000,
    profileImage: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?q=80&w=200',
    payouts: [
      {
        id: 'adv-1',
        date: '2024-02-15',
        amount: 50000,
        type: 'Advance Payment',
        status: 'Completed',
        reference: 'ADV-80031',
        period: 'N/A',
        breakdown: {
          grossCollection: 0,
          managementFee: 0,
          tax: 0,
          netPayout: 50000
        }
      }
    ]
  },
  {
    id: 'l-3',
    name: 'Prime Assets Ltd',
    email: 'mgmt@primeassets.co.ke',
    phone: '+254 733 111 222',
    propertyIds: [],
    payoutAccount: {
      bankName: 'Absa Bank',
      accountNumber: '5566778899',
      accountName: 'Prime Assets Corporate'
    },
    totalEarned: 0,
    pendingPayout: 0,
    advancePaid: 0,
    payouts: []
  }
];

export interface CalendarEvent {
  id: string;
  title: string;
  date: string;
  type: 'Inspection' | 'Maintenance' | 'Viewing' | 'Lease' | 'Other';
  time: string;
  location: string;
}

export const mockCalendarEvents: CalendarEvent[] = [
  { id: 'ev-1', title: 'Property Inspection - Elysian Heights', date: '2024-03-12', type: 'Inspection', time: '10:00 AM', location: 'Unit 101' },
  { id: 'ev-2', title: 'HVAC Repair Follow-up', date: '2024-03-12', type: 'Maintenance', time: '02:30 PM', location: 'Skyline Hub' },
  { id: 'ev-3', title: 'Viewing: Azure Bay #402', date: '2024-03-13', type: 'Viewing', time: '11:15 AM', location: 'Azure Bay' },
  { id: 'ev-4', title: 'Lease Signing - Michael Chen', date: '2024-03-14', type: 'Lease', time: '09:00 AM', location: 'Main Office' },
  { id: 'ev-5', title: 'Pest Control Visit', date: '2024-03-14', type: 'Maintenance', time: '01:00 PM', location: 'Golden District' },
];
export interface Employee {
  id: string;
  employeeNumber: string;
  name: string;
  email: string;
  phone: string;
  role: string;
  department: 'Administration' | 'Maintenance' | 'Security' | 'Finance' | 'Sales';
  status: 'Active' | 'On Leave' | 'Terminated';
  joinDate: string;
  salary: number;
  password?: string;
  profileImage?: string;
}

export interface Payroll {
  id: string;
  employeeId: string;
  employeeName: string;
  month: string;
  year: number;
  basicSalary: number;
  allowances: number;
  deductions: number;
  netPay: number;
  status: 'Paid' | 'Pending';
  paymentDate?: string;
}

export interface LeaveRequest {
  id: string;
  employeeId: string;
  employeeName: string;
  type: 'Annual' | 'Sick' | 'Maternity' | 'Paternity' | 'Personal';
  startDate: string;
  endDate: string;
  days: number;
  status: 'Approved' | 'Pending' | 'Rejected';
  reason: string;
}

export interface EmployeeAdvance {
  id: string;
  employeeId: string;
  employeeName: string;
  amount: number;
  dateRequested: string;
  repaymentPeriod: number; // months
  status: 'Approved' | 'Pending' | 'Fully Repaid';
  balance: number;
}

export const mockEmployees: Employee[] = [
  {
    id: 'emp-1',
    employeeNumber: 'PL-EMP-001',
    name: 'Kelvin Mwangi',
    email: 'k.mwangi@primelink.com',
    phone: '+254 700 111 222',
    role: 'Senior Property Manager',
    department: 'Administration',
    status: 'Active',
    joinDate: '2022-01-10',
    salary: 120000,
    password: 'password123',
    profileImage: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?q=80&w=200'
  },
  {
    id: 'emp-2',
    employeeNumber: 'PL-EMP-002',
    name: 'Faith Njeri',
    email: 'f.njeri@primelink.com',
    phone: '+254 700 333 444',
    role: 'Lead Maintenance Tech',
    department: 'Maintenance',
    status: 'Active',
    joinDate: '2022-03-15',
    salary: 85000,
    password: 'password123',
    profileImage: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?q=80&w=200'
  },
  {
    id: 'emp-3',
    employeeNumber: 'PL-EMP-003',
    name: 'Brian Otieno',
    email: 'b.otieno@primelink.com',
    phone: '+254 700 555 666',
    role: 'Accountant',
    department: 'Finance',
    status: 'On Leave',
    joinDate: '2022-06-01',
    salary: 95000,
    password: 'password123',
    profileImage: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=200'
  }
];

export const mockPayroll: Payroll[] = [
  {
    id: 'pyr-1',
    employeeId: 'emp-1',
    employeeName: 'Kelvin Mwangi',
    month: 'February',
    year: 2024,
    basicSalary: 120000,
    allowances: 15000,
    deductions: 5000,
    netPay: 130000,
    status: 'Paid',
    paymentDate: '2024-02-28'
  },
  {
    id: 'pyr-2',
    employeeId: 'emp-2',
    employeeName: 'Faith Njeri',
    month: 'February',
    year: 2024,
    basicSalary: 85000,
    allowances: 5000,
    deductions: 2000,
    netPay: 88000,
    status: 'Paid',
    paymentDate: '2024-02-28'
  }
];

export const mockLeaveRequests: LeaveRequest[] = [
  {
    id: 'lv-1',
    employeeId: 'emp-3',
    employeeName: 'Brian Otieno',
    type: 'Annual',
    startDate: '2024-03-10',
    endDate: '2024-03-17',
    days: 5,
    status: 'Approved',
    reason: 'Family vacation'
  },
  {
    id: 'lv-2',
    employeeId: 'emp-1',
    employeeName: 'Kelvin Mwangi',
    type: 'Sick',
    startDate: '2024-03-15',
    endDate: '2024-03-16',
    days: 1,
    status: 'Pending',
    reason: 'Medical checkup'
  }
];

export const mockAdvances: EmployeeAdvance[] = [
  {
    id: 'adv-101',
    employeeId: 'emp-2',
    employeeName: 'Faith Njeri',
    amount: 20000,
    dateRequested: '2024-02-15',
    repaymentPeriod: 2,
    status: 'Approved',
    balance: 10000
  }
];

export interface JobPosting {
  id: string;
  title: string;
  department: string;
  type: 'Full-time' | 'Part-time' | 'Contract' | 'Internship';
  status: 'Open' | 'Closed' | 'Draft';
  postedDate: string;
  applicantsCount: number;
}

export interface JobApplication {
  id: string;
  jobId: string;
  candidateName: string;
  email: string;
  status: 'Applied' | 'Screening' | 'Interview' | 'Offered' | 'Rejected';
  appliedDate: string;
}

export const mockJobPostings: JobPosting[] = [
  {
    id: 'job-1',
    title: 'Property Consultant',
    department: 'Sales',
    type: 'Full-time',
    status: 'Open',
    postedDate: '2024-03-01',
    applicantsCount: 12
  },
  {
    id: 'job-2',
    title: 'Security Guard',
    department: 'Security',
    type: 'Contract',
    status: 'Open',
    postedDate: '2024-03-05',
    applicantsCount: 45
  }
];

export const mockApplications: JobApplication[] = [
  {
    id: 'app-1',
    jobId: 'job-1',
    candidateName: 'Alice Johnson',
    email: 'alice.j@example.com',
    status: 'Interview',
    appliedDate: '2024-03-02'
  },
  {
    id: 'app-2',
    jobId: 'job-2',
    candidateName: 'John Muigai',
    email: 'j.muigai@gmail.com',
    status: 'Applied',
    appliedDate: '2024-03-06'
  }
];
