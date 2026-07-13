package main

import (
	"fmt"
	"net"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"runtime"
	"syscall"
	"time"
)

func main() {
	base, err := exeDir()
	if err != nil {
		fatal("定位程序目录失败: %v", err)
	}

	appDir := filepath.Join(base, "app")
	phpExe := filepath.Join(base, "runtime", "php", "php.exe")
	router := filepath.Join(appDir, "router.php")
	index := filepath.Join(appDir, "index.php")

	fmt.Println()
	fmt.Println(" ============================================")
	fmt.Println("   Outlook 邮箱助手  -  Windows 版")
	fmt.Println(" ============================================")
	fmt.Println()

	if !fileExists(index) {
		fatal("找不到 app\\index.php\n请把本程序放在完整安装目录内（与 app、runtime 同级）\n当前目录: %s", base)
	}
	if !fileExists(phpExe) {
		// try ensure_php.bat once
		ensure := filepath.Join(base, "tools", "ensure_php.bat")
		if fileExists(ensure) {
			fmt.Println("[信息] 首次运行，准备便携 PHP ...")
			c := exec.Command("cmd", "/c", ensure)
			c.Dir = base
			c.Stdout = os.Stdout
			c.Stderr = os.Stderr
			_ = c.Run()
		}
	}
	if !fileExists(phpExe) {
		fatal("找不到 runtime\\php\\php.exe\n请检查安装包是否完整，或运行 tools\\ensure_php.bat")
	}
	if !fileExists(router) {
		fatal("找不到 app\\router.php")
	}

	dataDir := filepath.Join(appDir, "data")
	_ = os.MkdirAll(dataDir, 0o755)
	_ = os.MkdirAll(filepath.Join(dataDir, "jobs"), 0o755)

	host := "127.0.0.1"
	port := pickPort([]int{17890, 17891, 17892, 17900, 18080, 18888, 19090})
	if port == 0 {
		fatal("无可用端口（17890 等均被占用）")
	}
	addr := fmt.Sprintf("%s:%d", host, port)
	url := fmt.Sprintf("http://%s/", addr)

	fmt.Printf("[信息] 目录: %s\n", base)
	fmt.Printf("[信息] PHP : %s\n", phpExe)
	fmt.Printf("[信息] 地址: %s\n", url)
	fmt.Println("[信息] 默认账号 admin / admin123 （首次登录强制改密）")
	fmt.Println("[信息] 关闭本窗口即停止服务")
	fmt.Println()

	cmd := exec.Command(phpExe, "-S", addr, "-t", appDir, router)
	cmd.Dir = appDir
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	// Windows: hide extra console for child if possible — keep attached for logs

	if err := cmd.Start(); err != nil {
		fatal("启动 PHP 失败: %v", err)
	}

	// wait ready
	ready := false
	for i := 0; i < 40; i++ {
		if portOpen(host, port) {
			ready = true
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if !ready {
		_ = cmd.Process.Kill()
		fatal("PHP 服务启动超时")
	}

	_ = openBrowser(url)
	fmt.Println("[信息] 浏览器已打开。按 Ctrl+C 停止。")

	// wait signal or process exit
	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, os.Interrupt, syscall.SIGTERM)

	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()

	select {
	case <-sigCh:
		fmt.Println("\n[信息] 正在停止...")
		killProcessTree(cmd)
	case err := <-done:
		if err != nil {
			fmt.Printf("\n[信息] 服务退出: %v\n", err)
		} else {
			fmt.Println("\n[信息] 服务已停止")
		}
	}

	// ensure dead
	killProcessTree(cmd)
	fmt.Println("再见。")
	// keep console if double-clicked
	if isConsoleOwned() {
		fmt.Print("按回车键退出...")
		_, _ = fmt.Scanln()
	}
}

func exeDir() (string, error) {
	exe, err := os.Executable()
	if err != nil {
		return "", err
	}
	exe, err = filepath.EvalSymlinks(exe)
	if err != nil {
		// ignore symlink errors
		exe, _ = os.Executable()
	}
	return filepath.Dir(exe), nil
}

func fileExists(p string) bool {
	st, err := os.Stat(p)
	return err == nil && !st.IsDir()
}

func pickPort(cands []int) int {
	for _, p := range cands {
		ln, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", p))
		if err == nil {
			_ = ln.Close()
			return p
		}
	}
	// random
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return 0
	}
	defer ln.Close()
	return ln.Addr().(*net.TCPAddr).Port
}

func portOpen(host string, port int) bool {
	c, err := net.DialTimeout("tcp", fmt.Sprintf("%s:%d", host, port), 200*time.Millisecond)
	if err != nil {
		return false
	}
	_ = c.Close()
	return true
}

func openBrowser(url string) error {
	switch runtime.GOOS {
	case "windows":
		return exec.Command("cmd", "/c", "start", "", url).Start()
	case "darwin":
		return exec.Command("open", url).Start()
	default:
		return exec.Command("xdg-open", url).Start()
	}
}

func killProcessTree(cmd *exec.Cmd) {
	if cmd == nil || cmd.Process == nil {
		return
	}
	pid := cmd.Process.Pid
	if runtime.GOOS == "windows" {
		// taskkill tree
		c := exec.Command("taskkill", "/PID", fmt.Sprintf("%d", pid), "/T", "/F")
		_ = c.Run()
		return
	}
	_ = cmd.Process.Signal(os.Interrupt)
	time.Sleep(300 * time.Millisecond)
	_ = cmd.Process.Kill()
}

func isConsoleOwned() bool {
	// heuristic: if started by explorer double-click, pause helps
	return true
}

func fatal(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "\n[错误] "+format+"\n", args...)
	fmt.Print("按回车键退出...")
	_, _ = fmt.Scanln()
	os.Exit(1)
}
