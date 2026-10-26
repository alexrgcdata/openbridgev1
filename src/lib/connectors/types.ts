export type ConnectorStatus = "available" | "planned" | "research";

export type ConnectorGoal =
  | "all"
  | "filemaker"
  | "accounting"
  | "payments"
  | "commerce"
  | "crm"
  | "productivity"
  | "files"
  | "developer";

export type ConnectorAuthType =
  | "filemaker-credentials"
  | "api-key"
  | "oauth2"
  | "connection-details"
  | "file-import";

export type ConnectorDefinition = {
  id: string;
  name: string;
  shortName: string;
  initials: string;
  goal: Exclude<ConnectorGoal, "all">;
  status: ConnectorStatus;
  summary: string;
  auth: {
    type: ConnectorAuthType;
    label: string;
    requirements: string[];
  };
  capabilities: string[];
};
