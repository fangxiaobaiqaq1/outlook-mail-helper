; Outlook 邮箱助手 - Inno Setup 安装脚本
; 编译: ISCC.exe setup.iss

#define MyAppName "Outlook邮箱助手"
#define MyAppNameEn "Outlook Mail Helper"
#define MyAppVersion "1.0.2"
#define MyAppPublisher "Outlook Mail Helper contributors"
#define MyAppURL "https://github.com/fangxiaobaiqaq1/outlook-mail-helper"
#define MyAppExeName "Outlook邮箱助手.exe"

[Setup]
AppId={{A7C3E91B-4D2F-4E8A-9B1C-0F6E2D8A5C41}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} {#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppPublisherURL={#MyAppURL}
AppSupportURL={#MyAppURL}
AppUpdatesURL={#MyAppURL}/releases
DefaultDirName={autopf}\{#MyAppName}
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
LicenseFile=payload\LICENSE
InfoBeforeFile=payload\USER_GUIDE.md
InfoAfterFile=payload\DISCLAIMER.md
OutputDir=output
OutputBaseFilename=OutlookMailHelper-Setup-{#MyAppVersion}
SetupIconFile=
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
PrivilegesRequired=lowest
PrivilegesRequiredOverridesAllowed=dialog
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
UninstallDisplayName={#MyAppName}
UninstallDisplayIcon={app}\{#MyAppExeName}
VersionInfoVersion=1.0.2.0
VersionInfoCompany={#MyAppPublisher}
VersionInfoDescription={#MyAppName} Setup
VersionInfoProductName={#MyAppName}
CloseApplications=yes
RestartApplications=no
; 允许装到当前用户目录，小白无管理员也能装
AllowNoIcons=yes
; 中文向导（系统有中文语言包时）
ShowLanguageDialog=auto

[Languages]
Name: "chinesesimplified"; MessagesFile: "compiler:Languages\ChineseSimplified.isl"
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "创建桌面快捷方式"; GroupDescription: "附加图标:"; Flags: checkedonce
Name: "quicklaunchicon"; Description: "创建开始菜单快捷方式"; GroupDescription: "附加图标:"; Flags: checkedonce

[Files]
; 主程序与运行时（排除用户数据）
Source: "payload\{#MyAppExeName}"; DestDir: "{app}"; Flags: ignoreversion
Source: "payload\启动.bat"; DestDir: "{app}"; Flags: ignoreversion
Source: "payload\README.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "payload\USER_GUIDE.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "payload\DISCLAIMER.md"; DestDir: "{app}"; Flags: ignoreversion
Source: "payload\LICENSE"; DestDir: "{app}"; Flags: ignoreversion
Source: "payload\NOTICE"; DestDir: "{app}"; Flags: ignoreversion
Source: "payload\app\*"; DestDir: "{app}\app"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "payload\runtime\*"; DestDir: "{app}\runtime"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "payload\tools\*"; DestDir: "{app}\tools"; Flags: ignoreversion recursesubdirs createallsubdirs
; 确保 data 目录存在（空）
Source: "payload\app\data\.gitkeep"; DestDir: "{app}\app\data"; Flags: ignoreversion

[Dirs]
Name: "{app}\app\data"; Permissions: users-modify
Name: "{app}\app\data\jobs"; Permissions: users-modify

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; WorkingDir: "{app}"
Name: "{group}\用户须知"; Filename: "{app}\USER_GUIDE.md"
Name: "{group}\卸载 {#MyAppName}"; Filename: "{uninstallexe}"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; WorkingDir: "{app}"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "立即运行 {#MyAppName}"; Flags: nowait postinstall skipifsilent

[UninstallDelete]
; 卸载时可选清理用户数据库（默认保留：用 Type: filesandordirs 会删）
; 这里只清临时 job 日志，保留 mail.db 以免误删号池——小白可手动删安装目录
Type: filesandordirs; Name: "{app}\app\data\jobs\*"

[Code]
function InitializeUninstall(): Boolean;
begin
  Result := True;
  if MsgBox('是否同时删除本地邮箱数据（mail.db）？' + #13#10 +
            '选「是」将清空所有导入的账号；选「否」仅卸载程序。',
            mbConfirmation, MB_YESNO) = IDYES then
  begin
    DelTree(ExpandConstant('{app}\app\data'), True, True, True);
  end;
end;
